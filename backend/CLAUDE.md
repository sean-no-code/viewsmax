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
- **Credits.** Wallet = `bavix/laravel-wallet` on `User` (`$user->balanceInt`). All amounts live in
  `config/credits.php` (env-overridable): per-tier monthly allowance under `subscription_credits.plans`
  (keyed by `plans.name`; there is deliberately no plan column or seeder value — `Plan` appends
  `monthly_credits` from config) and per-tool MCP costs under `mcp`. Every MCP `tools/call` is metered
  in `App\Mcp\Methods\SafeCallTool`: refused with a tool error when balance < cost, charged via
  `withdraw()` only on a non-error result (never negative), recorded as `credits_charged` on the
  `mcp_tool_invocations` audit row, and logged to the `credits` channel (`CREDITS_LOG_ENABLED`).
  In tests use `$this->fundCredits($user, n)` (see `tests/TestCase.php`) rather than `deposit()`.
  Website actions are priced the same way under `web` (same layout as `mcp`, both defaults 0):
  tag a route `credits.web:<action>` (`App\Http\Middleware\ChargeWebAction`); `credits.web:read`
  on the logged-in group prices GETs. Web prices are 0 in tests unless the test sets
  `protected bool $chargeWebActions = true`.
- **Posting (in progress).** Two parallel systems exist: `Post`/`PostTarget` and
  `SocialPost`/`SocialPostTarget`. One composed post fans out to many per-platform targets. Real
  publish-to-platform is still being built; compose/create endpoints (`POST /posts`) exist.

- **Signup source.** `users.signup_source` is `app` (SPA `/api/register`), `agent` (the API host's
  browser `/register`, reached mid OAuth from Claude/ChatGPT; `signup_client` holds the client name)
  or `admin`. NULL = before this existed and is treated as `app`. All signups go through
  `App\Services\Registration`. The agent flow continues to `/register/setup` (connect channels on
  this host via `App\Services\Social\SocialConnect` + `/connect/{platform}/callback`) and then back
  to the Passport consent screen; every provider app must allow that callback URL.

## Conventions

- API responses use a `{ success, message, data }` shape (see existing controllers).
- Soft-deletable models use Laravel's `SoftDeletes` trait + a `deleted_at` migration (e.g. `AiModel`).
- Keep new code in the style of the surrounding files.
