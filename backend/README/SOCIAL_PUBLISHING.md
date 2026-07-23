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
