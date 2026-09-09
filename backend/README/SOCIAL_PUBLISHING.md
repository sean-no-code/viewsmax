# Social Publishing — Architecture & API

Connect and post to Facebook, Instagram, Threads, LinkedIn, Bluesky, X, TikTok,
YouTube and Google My Business from one set of endpoints.

> **Setup (API keys / OAuth apps):** see `tasks/todo.md`.
> **Frontend integration contract:** see `tasks/frontend-todo.md`.

## Components

| Layer | Location |
|---|---|
| Config | `config/social.php` (per-platform credentials, scopes, enabled flags) |
| Tables | `social_accounts`, `social_posts`, `social_post_targets` |
| Models | `App\Models\SocialAccount`, `SocialPost`, `SocialPostTarget` |
| Provider contract | `App\Services\Social\Contracts\SocialProviderInterface` |
| Base classes | `AbstractSocialProvider`, `MetaGraphProvider`, `GoogleOAuthProvider` |
| Providers | `App\Services\Social\Providers\*Provider` (one per platform) |
| Resolver | `App\Services\Social\SocialProviderManager` |
| Controllers | `SocialAccountController`, `SocialPostController` |
| Async publish | `App\Jobs\PublishSocialPostJob` (one job per target) |
| Value objects | `App\Services\Social\Data\{OAuthResult, PublishResult}` |

## Data model

- **`social_accounts`** — one row per connected profile/page/location. A single
  OAuth grant can create several rows (e.g. every Facebook Page). OAuth tokens are
  **encrypted at rest** (`encrypted` cast) and hidden from API output.
- **`social_posts`** — the authored content (text + media + link).
- **`social_post_targets`** — one row per (post, account) delivery, each with its
  own status, remote id/url and error. Lets a post succeed on some platforms and
  fail on others, and be retried per-platform.

## Publish flow

1. `POST /api/social/posts` creates a `SocialPost` + a `SocialPostTarget` per chosen account.
2. A `PublishSocialPostJob` is dispatched **per target** (delayed if `scheduled_at` is set).
3. Each job: resolves the provider → `ensureFreshToken()` (refreshes if expired) →
   `publish()` → writes status/remote id/error onto the target → recomputes the
   parent post status (`published` / `partial` / `failed`).
4. `POST /api/social/posts/{id}/retry` re-queues only failed targets.

## Adding a new platform

1. Add a block to `config/social.php`.
2. Create `App\Services\Social\Providers\FooProvider` (extend `AbstractSocialProvider`
   or a shared base) implementing `getAuthorizationUrl`, `connectFromCode`,
   `ensureFreshToken`, and `publish`.
3. Register it in `SocialProviderManager::$providers`.

Routes, controllers, job and UI contract are platform-agnostic — no other changes needed.

## Endpoints (all under `/api`, auth required)

```
GET    /social/platforms                 list platforms + configured flag
GET    /social/accounts                  list connected accounts (?platform=)
DELETE /social/accounts/{id}             disconnect
GET    /social/{platform}/auth-url       begin OAuth (returns authorization_url + state)
POST   /social/{platform}/exchange       finish OAuth (code + state → account[])
POST   /social/{platform}/connect        non-OAuth connect (Bluesky: identifier + password)
GET    /social/posts                     list posts (paginated)
POST   /social/posts                     create + publish/schedule
GET    /social/posts/{id}                post + per-target status
POST   /social/posts/{id}/retry          retry failed targets
```

## Operational notes

- Publishing is async — **a queue worker must be running** (`php artisan queue:work`).
- Media must be **publicly reachable URLs** (several platforms fetch them server-side).
- Conservative privacy defaults: YouTube `private`, TikTok `SELF_ONLY` — change in the
  respective providers after audits pass.

## Automations (Instagram comment / story-reply / DM auto-responders)

ManyChat-style automations: when someone comments on a post or reel, replies
to a story, or DMs the account (optionally containing keywords), ViewsMax
posts an optional public reply and sends a DM — plain text, or a card with
one tracked button so the index can show CTR. Instagram only for now
(TikTok has no public DM API or comment webhooks).

### Pieces

- **Config** — `config/social.php` → `platforms.instagram.automations_enabled`
  adds the `instagram_business_manage_comments` + `instagram_business_manage_messages`
  scopes to the connect flow (existing accounts must reconnect) and gates
  create/start. Both scopes need **Meta App Review**.
- **Webhook intake** — `GET|POST /api/webhooks/instagram`
  (`InstagramWebhookController`): GET is Meta's verify handshake
  (`INSTAGRAM_WEBHOOK_VERIFY_TOKEN`), POST is verified with
  `X-Hub-Signature-256` over the raw body, written to the `automation_events`
  ledger (unique per event → redeliveries are no-ops) and queued.
- **Pipeline** — `ProcessInstagramInboundEventJob` (normalize → `AutomationMatcher`
  → one `automation_runs` row) then `ExecuteAutomationRunJob` (public reply →
  DM via private reply / direct message, per-run tracked links minted by
  `ShortLinkService::mintForRun`). Per-account throttle: `instagram-automations`
  rate limiter (20/min).
- **API** — `/api/automations` (index, accounts, media picker, CRUD, start,
  stop, runs). Plan cap: `plans.max_automations` (NULL = unlimited).
- **MCP tools** — `list_automations`, `create_automation`, `update_automation`,
  `start_automation`, `stop_automation`, `delete_automation`, `get_automation_runs`.
- **Housekeeping** — `automations:ensure-subscriptions` (daily) re-subscribes
  stale accounts with live automations; `automations:prune-events --days=30`
  trims the ledger.
- **Logging** — everything goes to the `automations` channel
  (`storage/logs/automations-YYYY-MM-DD.log`, rotated daily). Turn it off with
  `AUTOMATIONS_LOG_ENABLED=false`; tune with `AUTOMATIONS_LOG_LEVEL` /
  `AUTOMATIONS_LOG_DAYS`. Run `php artisan config:clear` after changing them
  when config is cached.

### Meta dashboard checklist

1. Instagram product → Webhooks: callback URL `https://<api>/api/webhooks/instagram`,
   verify token = `INSTAGRAM_WEBHOOK_VERIFY_TOKEN`, subscribe to `comments` and `messages`.
2. App Review: request `instagram_business_manage_comments` and
   `instagram_business_manage_messages` (until approved only app testers get events).
3. Set `INSTAGRAM_AUTOMATIONS_ENABLED=true`, reconnect each Instagram account
   (the Automations page shows a "Reconnect Instagram" notice until it carries the scopes).
4. Start an automation — that account is subscribed via `/{ig-user-id}/subscribed_apps`
   and `social_accounts.webhook_subscribed_at` is set.

### Env vars

```
INSTAGRAM_AUTOMATIONS_ENABLED=false      # flip on after App Review
INSTAGRAM_WEBHOOK_VERIFY_TOKEN=          # any long random string, same value in the Meta dashboard
INSTAGRAM_WEBHOOK_SIGNATURE_CHECK=true   # local-dev switch only; never false in production
AUTOMATIONS_LOG_ENABLED=true             # false = silence the automations log entirely
AUTOMATIONS_LOG_LEVEL=debug              # info hides raw-payload lines
AUTOMATIONS_LOG_DAYS=14                  # rotation
LIMIT_FREE_AUTOMATIONS=1 LIMIT_STARTER_AUTOMATIONS=3 LIMIT_CREATOR_AUTOMATIONS=10   # PlanSeeder; pro/agency unlimited
```

### Gotchas

- Meta allows **one** private reply per comment, so a DM with a button is a
  single generic-template card (title ≤ 80 chars). Text DMs keep 1000 chars.
- Story replies / DMs must be answered within 24h of the person's message —
  a queue backlog past that shows as `outside_24h_window` on the run.
- Our own public replies come back as `comments` events and our DMs as
  `is_echo` messages; the normalizer drops both. The per-sender cooldown
  (default 24h) stops "DM contains any word" answering every follow-up.
