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

## Conventions

- API responses use a `{ success, message, data }` shape (see existing controllers).
- Soft-deletable models use Laravel's `SoftDeletes` trait + a `deleted_at` migration (e.g. `AiModel`).
- Keep new code in the style of the surrounding files.
