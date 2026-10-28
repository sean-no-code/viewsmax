# CLAUDE.md

Guidance for working in this repository.

## Stack

- **Laravel 12** / **PHP 8.2** backend (API-only; SPA frontend lives in a separate repo).
- **MySQL/Postgres** in prod; an auxiliary `outlier_db` Postgres connection for outlier data.
- **Stripe** for billing/subscriptions.
- **PHPUnit 11** for tests (Feature + Unit suites), running on in-memory SQLite.

## Workflow — TDD is required

All new behavior is **test-driven**. For any new endpoint, limit, or business rule:

1. **Write a failing test first** (Feature test for API/endpoint behavior, Unit test for isolated logic).
2. Run it and watch it fail for the right reason.
3. Implement the minimum to make it pass.
4. Refactor with the test green.

Do not add or change endpoint/business logic without a test covering it. Extend existing tests where one
already covers the area (e.g. `tests/Feature/OfferFlowTest.php` for offers) rather than starting cold.

### Running tests

```bash
composer test            # runs `php artisan test`
php artisan test --filter=SomeTest
```

Tests use in-memory SQLite (see `phpunit.xml`) — no external DB needed.

## Layout

- `app/Models` — Eloquent models.
- `app/Http/Controllers` — API controllers (+ `Admin/` for admin-only).
- `app/Http/Middleware` — incl. plan/credit gating (`CheckPlan`, `RestrictFreePlan`).
- `app/Services` — integrations + business logic (`StripeService`, `CreditService`, etc.).
- `app/Observers` — model lifecycle hooks (a good home for limit enforcement).
- `routes/api.php` — all routes.
- `database/migrations`, `database/seeders` — schema + seed data (e.g. `PlanSeeder`).
- `tests/Feature`, `tests/Unit` — test suites.

## Domain notes (non-obvious)

- **"Offer" is an alias for the `tracking_events` table.** `App\Models\Offer` sets
  `protected $table = 'tracking_events'`. An Offer is a promotion/campaign being tracked: it has
  `offer_url` (was `landing_page_url`), `conversion_url`, `conversion_value`, and owns tracking links,
  clicks, and conversions. FKs on related tables are still named `tracking_event_id`.
- **Plans & limits.** `plans` (model `Plan`) defines tiers; users link via `user_plans` pivot
  (`UserPlan`) carrying Stripe subscription/status. `CheckPlan` middleware currently gates by plan *name*,
  not by numeric limits.
- **Billing.** `BillingController` + `StripeService` handle Stripe. Subscriptions live on the
  `user_plans` pivot (`stripe_subscription_id`, `status`, `expires_at`).
- **Posting (in progress).** Two parallel systems exist: `Post`/`PostTarget` and
  `SocialPost`/`SocialPostTarget`. One composed post fans out to many per-platform targets. Real
  publish-to-platform is still being built; compose/create endpoints (`POST /posts`) exist.

- **Signup source.** `users.signup_source` is `app` (SPA `/api/register`), `agent` (the API host's
  browser `/register`, reached mid OAuth from Claude/ChatGPT; `signup_client` holds the client name)
  or `admin`. NULL = before this existed and is treated as `app`. All signups go through
  `App\Services\Registration`. The agent flow continues to `/register/setup` (connect channels on
  this host via `App\Services\Social\SocialConnect` + `/connect/{platform}/callback`) and then back
  to the Passport consent screen; every provider app must allow that callback URL.

- **Handle claims.** `social_handle_claims` (`App\Services\Social\HandleClaims::claim`) records which user first
  connected each social account (platform + platform account id + handle) and is never deleted — not on
  disconnect, not by `DELETE /api/user-data`. Every connect path calls it (`AbstractSocialProvider::storeAccount`,
  `YouTubeChannelService::createOrUpdateChannel`, `OAuthConnectionService::exchange`); another user connecting the
  same account gets `HandleClaimedException` ("sign in with that account"). New connect paths must call it too.

- **Access gating.** `App\Support\UserAccess::denial($user)` is the single rule set for "may this user use
  ViewsMax right now" (unverified email, then `access_expired`: no active/trialing plan and no open free
  window — a user with no window at all is expired too — then the paywall: onboarding not finished and no
  card — `payment_required`; skipped when `SIGNUP_CARD_REQUIRED=false`, which makes the window a card-free trial — `UserAccess::cardRequired()`, mirrored in `User::canCompleteOnboarding`, `scopeWithAccess` and the SPA's `cardOptional`). Admins and `promotional_customer` role holders are exempt after verification.
  Add new rules there, not in a middleware. **Background work follows the same rule**: commands that select
  records in bulk use `User::withAccess()` (`->whereHas('user', fn ($u) => $u->withAccess())`), jobs that
  handle one record call `UserAccess::allowsBackgroundWork($user)`; a lapsed user's due post goes back to
  draft (`Post::revertToDraft`), never published. The factory user has an open window (has access); use
  `User::factory()->expired()` / `->withoutWindow()` / `->withPlan('cancelled')` / `->promotional()` / `->admin()`. At the card step
  the deliveries keep the pre-card endpoints open (`EnsureAccessActive::ONBOARDING_PATTERNS`,
  `EnsureWebAccess::ONBOARDING_ROUTES`); agent signups add the card at `/register/billing`
  (`WebBillingController`), app signups in the SPA's onboarding. Both go through `SubscriptionStarter`. Delivery differs per entry point: `EnsureAccessActive` (REST 403 with `code`), `SafeCallTool`
  (MCP `isError` tool result at HTTP 200 — never an HTTP error, which counts against directory ranking),
  `EnsureWebAccess` (web redirect). Console code calls `denial()` directly.

- **Analytics is the Pro tier.** `App\Support\AnalyticsAccess::allows($user)` (plans `pro`, `agency` and the
  three retired `creator_*` rows; admins + `promotional_customer` exempt) is the single rule for channel and
  post analytics, with `User::withAnalytics()` as its SQL twin for bulk selects. It is **orthogonal to
  access**: a lapsed Pro user passes `withAnalytics()` and fails `withAccess()`, so the nightly commands
  (`channels:sync-videos`, `audience:refresh`, `posts:refresh-metrics`, `posts:import-recent`) chain both.
  Below Pro, a connect queues no import (`YouTubeOAuthController`, `SocialConnect::queueRecentPostsImport`)
  and skips the synchronous `fetchAndStoreChannelAnalytics`; `ImportChannelVideosJob` /
  `ImportRecentPostsJob` re-check the owner because a queued job can outlive a downgrade; `POST
  /channels/{channel}/refresh` 403s. Reads of stored analytics are not gated (they cost nothing) — the SPA
  hides the pages via `can_use_analytics` in `User::apiPayload()`. What stays open on every tier: `GET
  /channels/{id}/videos` and `POST /channels/{id}/fetch-videos`, which the Review page (a Free-plan feature)
  and the offer video picker use to import on demand. The `user_plans` pivot fires no model events (two
  plan-change paths are raw `DB::table` updates), so every path that writes it calls
  `AnalyticsAccess::backfill($user)` instead of an observer. In tests, `$this->analyticsUser()` /
  `$this->planNamed('creator')` (`tests/TestCase.php`) give a user with or without analytics.

## Conventions

- API responses use a `{ success, message, data }` shape (see existing controllers).
- Soft-deletable models use Laravel's `SoftDeletes` trait + a `deleted_at` migration (e.g. `AiModel`).
- Keep new code in the style of the surrounding files.

## YouTube API quota (`app/Support/Quota`)

Google allows 100 `search.list` calls, 100 `videos.insert` calls and 10,000 units a
day by default, reset at midnight Pacific. The app counts every YouTube call it
makes (`App\Listeners\RecordYouTubeQuotaSpend`, from the HTTP client's
`ResponseReceived` event — no call site reports anything) into a cache counter
per Pacific day and a ledger (`youtube_quota_spends`, admin dashboard at
`/api/admin/youtube-quota/*`). The two spend points — queuing an outlier search
fetch (`YouTubeSearchService`) and dispatching a YouTube publish
(`PostPublishDispatcher`) — ask `App\Contracts\Quota\QuotaGate` first; the gate
compares the shared counter with the tier's `.env` threshold
(`YOUTUBE_QUOTA_SEARCH_FREE/PAID`, `YOUTUBE_QUOTA_UPLOAD_FREE/PAID`) and then the
user's own daily allowance of Google-bound fetches
(`YOUTUBE_QUOTA_SEARCH_PER_USER_FREE/PAID`; see `config/services.php` →
`youtube.quota`), failing open if the cache is down. A refusal is a 429
`code: quota_paused` whose message (`App\Support\Quota\QuotaMessage`) names the
plan and, for free users, ends with an "Upgrade …" sentence that the MCP layer
strips — AI clients may be told about a limit, never shown an upsell. The same
gate holds per-user daily allowances for YouTube uploads
(`YOUTUBE_QUOTA_UPLOAD_PER_USER_*`, counted once per post target) and outlier
breakdowns (`BREAKDOWNS_PER_USER_*`, Gemini cost, not YouTube quota; reusing an
existing breakdown never counts).
`App\Support\Quota\Tier::of` maps a user to `free` / `paid` / `admin`.
`storage/logs/youtube-quota.log` says per search whether it was served from
cache or fetched from YouTube. `App\Support\Quota\QuotaAlerts` emails
`ADMIN_EMAIL` (and logs at error level, so Sentry sees it) once a day per
resource at `YOUTUBE_QUOTA_ALERT_AT`% of Google's allocation
(`YOUTUBE_QUOTA_SEARCH/UPLOAD_DAILY_LIMIT`), at 100 %, and when Google answers
`quotaExceeded` — same pattern as the queue heartbeat (`config/monitoring.php`).
Searches are deduped globally, so limit *novel fetches*, never requests.
