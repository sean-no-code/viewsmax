# Pricing — Full Subscription Lifecycle Test Plan (local)

Goal: exercise the **entire** subscription lifecycle locally the way it behaves in
production — trial, trial→active conversion, upgrade/downgrade (trial & active),
renewal, cancel, expiry, payment failure — and verify the DB pivot and UI
at each step.

> **Scope:** credits/wallet are **out of scope** (client deferred them — same amount
> for every tier). Judge every scenario on the pivot **`status`** + **`expires_at`**
> (and price/plan on up/downgrades). Credit balances are not a pass/fail signal.

This complements [PRICING_MANUAL_TESTING.md](PRICING_MANUAL_TESTING.md) (feature
checklist). This doc is about **state transitions over time**.

---

## 0. How the lifecycle is modeled (read first)

Each subscription is a row in **`user_plans`** (the pivot) with a `status`:

| status | meaning | how it's reached |
|---|---|---|
| `trialing` | in the free trial, no charge yet | new subscribe (7-day trial) |
| `active` | paying normally | trial converts (`invoice.paid`) or renews |
| *(cancelled_at set, status stays)* | cancelled but still has access | graceful cancel → access until `expires_at` |
| `past_due` | a renewal payment failed | `invoice.payment_failed` webhook |
| `expired` | access ended, moved off the plan | `subscriptions:process-expired` after grace |

Key mechanics:
- **Graceful cancel keeps `status` (`active`/`trialing`) and only sets `cancelled_at`** — the user keeps access until `expires_at`. It does **not** immediately drop them.
- **Expiry is a job, not a webhook:** `php artisan subscriptions:process-expired` flips expired rows to `expired`. It runs on a schedule in prod; we run it by hand locally. Grace period = `SUBSCRIPTION_GRACE_PERIOD_HOURS` (24h).
- **The expiry job re-checks Stripe first.** If Stripe still reports the sub `active`/`trialing`, the job *corrects the local row back to active* (webhook-lag protection). So to test expiry you must make Stripe actually end the sub (cancel **immediately**, or advance a **test clock** past period end) — back-dating `expires_at` alone won't expire a sub Stripe still thinks is live.
- **Upgrade/downgrade** syncs the local pivot immediately (price + plan), and also via the `customer.subscription.updated` webhook.

---

## 1. Setup (3 terminals + tools)

```bash
# T1 — backend
php artisan serve --host=0.0.0.0 --port=8000

# T2 — forward Stripe webhooks (real events drive the lifecycle)
stripe listen --forward-to localhost:8000/api/webhooks/stripe --api-key sk_test_...

# T3 — frontend
npm run dev        # http://localhost:8080
```
- Set `STRIPE_SIGNATURE_CHECK=false` locally (or paste the `whsec_…` from T2 into `STRIPE_WEBHOOK_SECRET` and restart T1).
- Run `php artisan config:clear` after any `.env` change.

**Test cards** (Stripe test mode):
| purpose | number |
|---|---|
| success | `4242 4242 4242 4242` |
| renewal fails (attaches, fails on charge) | `4000 0000 0000 0341` |
| generic decline | `4000 0000 0000 0002` |

**State inspector** — dump a user's current plan state at any point. Dumps **all**
pivots (so a reused user with duplicate rows can't fool a `->first()`):
```bash
php artisan tinker --execute="\$u=App\Models\User::where('email','TEST@EMAIL')->first(); dump(['email'=>\$u->email]); \$u->plans()->withPivot(['stripe_subscription_id','status','cancelled_at','expires_at','stripe_price_id'])->get()->each(fn(\$p)=>dump([\$p->name=>\$p->pivot->stripe_subscription_id,'status'=>\$p->pivot->status,'cancelled_at'=>\$p->pivot->cancelled_at,'expires'=>\$p->pivot->expires_at,'price'=>\$p->pivot->stripe_price_id]));"
```

---

## 2. Scenarios A — user-driven (real Stripe + webhooks, no time travel)

Each scenario lists the **action**, **expected pivot**, **expected UI**.

### A1 · New subscribe (→ trialing)
- Onboarding → Connect → **Choose plan** (e.g. Pro) → pay with `4242`.
- Pivot: `status=trialing`, `stripe_price_id=<Pro>`, `cancelled_at=null`, `expires_at≈now+7d`.
- UI: lands in app; billing shows **Pro · Current Plan**; header "Manage Your Plan".

### A2 · Upgrade during trial (Pro → Agency)
- Billing → **Upgrade** on Agency.
- T2 shows `customer.subscription.updated`. Pivot: `plan=agency`, `price=<Agency>`, **still `trialing`** (trial continues — clock not reset).
- UI: badge moves to Agency immediately.

### A3 · Downgrade during trial — blocked then allowed
- Create offers above the target cap (e.g. 3 offers), then **Downgrade** to Starter (cap 1).
- Expect **422** block toast: "…delete offers down to 1…". Pivot unchanged.
- Delete offers (Landing Pages → open page → **Delete**) down to 1 → Downgrade again → succeeds; pivot `plan=starter`, still `trialing`.

### A4 · Cancel during trial (graceful)
- Billing → **Cancel Subscription** → confirm.
- Pivot: `cancelled_at=now()`, **status still `trialing`**, `expires_at` = trial end.
- UI: card shows "Cancelled — access until <trial end>".
- (Access continues until expiry — see C1 to actually expire it.)

### A5 · Cancel during active
- Same as A4 but from an `active` sub: `cancelled_at` set, status stays `active`, access until period end.

---

## 3. Scenarios B — time-driven via Stripe Test Clocks (trial→active, renewal)

Trial conversion and renewals are **Stripe time events**. To trigger them locally
without waiting days, use a **Stripe Test Clock** so Stripe fires the real
`invoice.paid` / `customer.subscription.updated` webhooks into your `stripe listen`.

> The app creates customers without a clock, so for these two scenarios create the
> subscription **on a test-clock customer** via the Stripe CLI, then attach it to a
> local user. This still exercises the real webhook→DB path (which is the prod path).

### One-time: make a clock-backed subscription (one command)
A dev helper does the whole setup — creates the test clock, a clock-bound customer
with a test card, a trial subscription on the chosen tier, and links it to the
local user (the user must already exist, e.g. registered through the app):
```bash
php artisan dev:make-clock-sub clocktest@email pro      # plan defaults to pro; --trial-days=7
```
It prints the IDs — **note `clk_xxx`** (you'll advance it below):
```
  clock:        clk_xxx
  customer:     cus_xxx
  subscription: sub_xxx  (status: trialing)
```
> Prereqs: plans seeded (so `pro` has a `stripe_price_id`) and `STRIPE_SECRET` set.
> The command refuses to run when `APP_ENV=production`.

<details><summary>Manual equivalent (if you'd rather not use the helper)</summary>

```bash
stripe test_helpers test_clocks create --frozen-time $(date +%s) --api-key sk_test_...   # -> clk_xxx
stripe customers create --api-key sk_test_... -d "test_clock=clk_xxx" -d "email=clocktest@email"  # -> cus_xxx
stripe payment_methods attach pm_card_visa --api-key sk_test_... -d "customer=cus_xxx"
stripe customers update cus_xxx --api-key sk_test_... -d "invoice_settings[default_payment_method]=pm_card_visa"
stripe subscriptions create --api-key sk_test_... -d "customer=cus_xxx" -d "items[0][price]=$STRIPE_PRICE_PRO" -d "trial_period_days=7"  # -> sub_xxx
# then link it to the user:
php artisan tinker --execute="\$u=App\Models\User::where('email','clocktest@email')->first(); \$u->update(['stripe_customer_id'=>'cus_xxx']); \$p=App\Models\Plan::where('name','pro')->first(); \$u->plans()->syncWithoutDetaching([\$p->id=>['stripe_subscription_id'=>'sub_xxx','stripe_price_id'=>env('STRIPE_PRICE_PRO'),'status'=>'trialing','starts_at'=>now(),'expires_at'=>now()->addDays(7)]]);"
```
</details>

### B1 · Trial → active conversion ✅ PASS (2026-06-23; re-verified clean on `WossenB24` after the `invoice_payment.paid` fix)
```bash
# advance the clock 8 days (past trial_end)
stripe test_helpers test_clocks advance clk_xxx --frozen-time $(date -v+8d +%s) --api-key sk_test_...
```
- Stripe charges the card and fires the paid-invoice event(s) + `customer.subscription.updated` → T2 forwards them.
- Pivot: `status=active`, `expires_at` ≈ now+1 month.
- **Result:** trialing→**active**, expires_at→**+1 month**. Verified via pivot dump.
- **Webhook gotcha (fixed):** newer Stripe accounts report the paid invoice via
  `invoice_payment.paid` (object = `InvoicePayment`) and may **not** send legacy
  `invoice.paid`. The controller now handles `invoice_payment.paid` (resolves the
  invoice → same handler, idempotent). Status/expiry also sync via
  `customer.subscription.updated` independently.
- **Inspector gotcha:** dump **all** pivots — a `->first()` can return a *stale
  duplicate pivot* if a user has more than one row for the same plan.

### B2 · Renewal (active → next period)
```bash
# advance ~1 month past the current period end
stripe test_helpers test_clocks advance clk_xxx --frozen-time $(date -v+40d +%s) --api-key sk_test_...
```
- The paid-invoice event fires again. Pivot `expires_at` moves to the next period; `status` stays `active`.

### B3 · Renewal payment fails (→ past_due)
- Rebuild the clock sub using a failing renewal card (`pm_card_chargeCustomerFail` or price + `4000 0000 0000 0341`), advance past period end.
- `invoice.payment_failed` fires → Pivot `status=past_due`. UI should reflect loss of access on next gate.

> **About proration:** proration is computed and charged by **Stripe**, not us — our
> `changeSubscriptionPrice` sends `proration_behavior: create_prorations` and the
> webhook just syncs the new price/plan/expiry. So we verify *that Stripe applied a
> proration* (invoice line items), not exact amounts. Proration only happens on a
> **paid/active** sub — none occurs during the trial (that's why A2/A3 show no charge).

### B4 · Upgrade during the ACTIVE period (proration charge)
- Precondition: sub is `active` (do B1 first). Billing → **Upgrade** to a higher tier.
- Pivot: `plan`/`price` move up, status stays `active`.
- Stripe: an **immediate prorated invoice** — a charge for the remainder of the period
  at the new (higher) price, minus an adjustment for the unused portion of the old price.
- Verify in Stripe Dashboard (test) → the subscription's latest/upcoming invoice shows
  two proration line items (negative adjustment for old price + charge for new). Unlike A2
  (trial), money actually moves here.

### B5 · Downgrade during the ACTIVE period (proration + over-cap)
- Precondition: `active`. Over-cap block still applies — if over the target's offer cap,
  expect the 422 "delete offers down to N" first; delete, then retry.
- Pivot: `plan`/`price` move down, status `active`.
- Stripe: a **proration adjustment** (negative line) is created and applied against the next
  invoice (no immediate charge for a downgrade). Verify it on the upcoming invoice.

### B6 · Recover from past_due (→ active)
- Precondition: `past_due` (from B3). Pay the open invoice (Stripe Dashboard → invoice →
  "Pay", or advance the clock to the next automatic retry with a working card).
- The paid-invoice event fires → Pivot `status=active` again.

### B7 · Up/downgrade a CANCELLED subscription → reactivates it
- Precondition: a **gracefully cancelled** sub (do A4 or A5 first) — `cancelled_at` set,
  `status` still `active`/`trialing`, Stripe `cancel_at_period_end=true`, UI shows
  "Cancelled — access until …".
- Action: Billing → **Upgrade** or **Downgrade** to another tier.
- **Expected: the change reactivates the subscription.** `changeSubscriptionPrice` always
  sends `cancel_at_period_end=false`, so Stripe clears the scheduled cancellation and
  swaps the price.
  - Stripe: `cancel_at_period_end=false`, new price, status `active`/`trialing` (unchanged
    trial), proration applied (charge for an upgrade; adjustment for a downgrade).
  - Pivot: `plan`/`price` move to the target (set immediately by `changePlan`), and
    `cancelled_at` is **cleared back to `null`** by the `customer.subscription.updated`
    webhook (brief lag: the "Cancelled — access until" line persists until the webhook
    lands, then a refresh shows the normal "Free trial · ends …" / "Renews …" line).
  - UI: the cancel banner disappears; the **Current Plan** badge moves to the new tier.
- Downgrade caveat: the **over-cap guard still applies** — if you're over the target's
  offer cap you get the 422 "delete offers down to N" first (same as B5); the
  reactivation only happens once the change actually goes through.
- ⚠️ This means a user **cannot** "change plan" and stay cancelled — switching tiers is
  treated as a re-commitment. To leave after switching, they must cancel again.

---

## 4. Scenarios C — expiry (the artisan job)

### C1 · Expire a cancelled/ended subscription
The expiry job only expires subs Stripe no longer reports as live, so first make
Stripe actually end it, **then** run the job.

**Option 1 — immediate cancel (fastest):**
```bash
# cancel NOW at Stripe (not at period end)
stripe subscriptions cancel sub_xxx --api-key sk_test_...
# back-date local expiry past the grace window
php artisan tinker --execute="\$u=App\Models\User::where('email','TEST@EMAIL')->first(); \$id=\$u->plans()->wherePivotIn('status',['active','cancelled','trialing'])->first()->id; \$u->plans()->updateExistingPivot(\$id,['expires_at'=>now()->subDays(2),'status'=>'active']);"
# run the prod job
php artisan subscriptions:process-expired
```
**Option 2 — test clock:** advance `clk_xxx` past the (cancelled-at-period-end) period end so Stripe cancels it, then run the job.

- Result: Pivot `status=expired`; user is effectively on Free.
- UI: no Current Plan badge; pricing page back to "Choose Your Growth Plan"; offer creation blocked (Free cap 0).
- Verify with the state inspector: `status=expired`.

### C2 · Grace period
- Back-date `expires_at` to only **1 hour ago** and run the job → it should **not** expire yet (within the 24h grace). Back-date to **>24h ago** → it expires.

---

## 5. Scenarios D — webhook-only checks (ad hoc)

For quick webhook wiring checks without a clock:
```bash
stripe trigger customer.subscription.updated --api-key sk_test_...
stripe trigger invoice.payment_failed --api-key sk_test_...
```
(These use generic fixtures, so they won't map to a specific local user — use them to
confirm the endpoint receives/parses events, not for per-user state.)

---

## 6. Full lifecycle run (end-to-end, one pass)

A single sequence that walks the whole life of a subscription:

1. **A1** subscribe Pro → `trialing`.
2. **A2** upgrade to Agency → `trialing`, plan=agency.
3. **B1** advance clock 8d → `active`, expires +1mo.
4. **B2** advance ~1mo → `active`, expires rolls forward again.
5. **A5** cancel → `cancelled_at` set, status `active`, "access until <date>".
6. **C1 opt-2** advance clock past period end + run job → `expired`, on Free.
7. **A1 again** re-subscribe → back to `trialing`. (Confirms re-subscribe after expiry.)

---

## 7. Reset between runs

To re-test from a clean slate for a user:
```bash
php artisan tinker --execute="\$u=App\Models\User::where('email','TEST@EMAIL')->first(); \$u->plans()->detach(); \$u->update(['onboarding_completed_at'=>null]);"
```
Cancel leftover Stripe test subs from the Stripe dashboard (test mode) or
`stripe subscriptions cancel sub_xxx`. Delete test clocks you're done with:
`stripe test_helpers test_clocks delete clk_xxx`.

---

## 8. Pass/fail summary grid

Tester: _____   Date: _____   (⬜ pending · ✅ pass · ❌ fail — see notes below)

Judged on `status` + `expires_at` (+ plan/price on changes). Credits are out of scope.

| # | Scenario | Expected `status` | Expiry / pivot | UI | Result |
|---|---|---|---|---|---|
| A1 | subscribe (trial) | trialing | +7d | Current Plan badge | ✅ |
| A2 | upgrade in trial | trialing | plan=agency | badge moves up | ✅ |
| A3 | downgrade over cap | (unchanged) | — | 422 block, then allowed | ✅ |
| A4 | cancel in trial | trialing + cancelled_at | expiry unchanged | "access until …" | ✅ |
| A5 | cancel in active | active + cancelled_at | expiry unchanged | "access until …" | ✅ |
| B1 | trial→active | active | +1 month | still Current Plan | ✅ |
| B2 | renewal | active | rolls forward | Renews {date} | ✅ |
| B3 | renewal fails | past_due | — | access gated | ✅ |
| B4 | upgrade in active | active | plan/price up + proration | badge up | ✅ |
| B5 | downgrade in active | active | plan/price down + proration | badge down | ✅ |
| B6 | past_due → recovery | active | — | access restored (in-app Billing Portal) | ✅ |
| B7 | up/downgrade a cancelled sub | active/trialing (reactivated) | cancelled_at→null, plan/price change | cancel banner gone, badge moves | ✅ |
| C1 | expiry job | expired | — | back to Free | ✅ |
| C2 | within grace | unchanged | unchanged | — | ✅ |

### Notes / failures
- **A1 ✅** — Agency selected → `plan=agency`, `status=trialing`, `price=<Agency>`,
  +7d. Confirms: subscribe honors the chosen tier, status correctly `trialing` (after
  the `handleInvoicePaid` fix), and the header badge is per-plan.
- **A2 ✅** — Starter→Pro upgrade in trial → `plan=pro`, `status=trialing` (trial
  continues, no charge), `price=<Pro>`. Upgrade syncs locally.
- **A3 ✅** — created 5 offers (higher tier), soft-deleted 4 (rows kept, `deleted_at`
  set) down to 1, then downgraded to Starter → `plan=starter`, `status=trialing`,
  `active_offers=1`. Soft-deleted offers don't count toward the cap (that's why the
  downgrade was allowed); over-cap downgrade is rejected before Stripe (no event).
- **A4 ✅** — cancel in trial → `cancelled_at` set, `status=trialing` (access kept),
  `expires_at` unchanged. Graceful cancel: marked, not dropped.
- **A5 ✅ (full UI pass)** — no-trial active sub (via `dev:make-clock-sub --trial-days=0`):
  `status=active`, `expires_at` ~1 month. Clicked **Cancel Subscription** in the browser → UI "Cancelled — access until
  Jul 23, 2026"; DB: `cancelled_at` set, `status` stays `active`, `expires_at` unchanged;
  Stripe: `cancel_at_period_end=true`. UI → DB → Stripe all agree.
- **B2 ✅** — renewal: advanced clock past period end → `invoice_payment.paid` fired,
  `status` stays `active`, `expires_at` rolled forward ~1 month (Jul 23 → Aug 23). UI
  card reads "Renews {new date}".
- **B3 ✅** — failing-card renewal (`pm_card_chargeCustomerFail` set as default, then
  advanced past period end) → `invoice.payment_failed` → `status=past_due`; badge drops
  to FREE. **Surfaced + fixed a real gap:** offer-cap enforcement only ran for users with
  an *active* plan, so a `past_due`/lapsed user got **unlimited** offers. Now falls back to
  the Free cap (0) when there's no active plan — blocked in the API (422, with test
  `test_user_without_an_active_plan_is_capped_at_the_free_limit`) and gated in the UI
  (Landing Pages "+ Add landing page" → "Upgrade to add more").
- **B4 ✅** — day-1 Creator→Pro upgrade: pivot moved to `pro`/`active` (same sub id,
  changed in place). Stripe proration confirmed via **pending invoice items** (not an
  immediate invoice — `changeSubscriptionPrice` doesn't force one): `−$59 unused Creator`
  + `+$99 remaining Pro` = net **+$40** (= Pro−Creator price diff), proving day-1 prorates
  over the full remaining period. Check `stripe invoiceitems list --customer …`, not
  `invoices list`, to see the proration. (Round-trip changes net to $0 — correct.)
- **Stripe cross-check (A1–A4):** `stripe subscriptions retrieve` confirms the live
  subscriptions match our DB — A1 price=Agency/trialing; A2 price=Pro/trialing
  (upgrade applied at Stripe); A3 price=Starter/trialing (downgrade applied at
  Stripe); A4 `cancel_at_period_end=true` while trialing. UI → DB → Stripe all agree.
_(I'll log any ❌ here with what happened + the fix as we go.)_
