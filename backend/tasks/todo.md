# Offers + Content API — Plan (REVISED after discovering in-flight refactor)

## Key discovery
This worktree has an in-flight refactor renaming `TrackingEvent` → **`Offer`** (model `Offer` → `tracking_events` table, `User::offers()`, `landing_page_url`→`offer_url`). So the flow **login → offer → links → conversions → analytics ALREADY EXISTS**:
- Login: `POST /api/login` (AuthController, Sanctum)
- Offers: `apiResource tracking-events` (TrackingEventController) — model `Offer`
- Links: `apiResource tracking-links` (TrackingLinkController)
- Conversion events: public `POST /api/track/conversion`, `POST /api/track/click` (PixelController)
- Analytics: `GET tracking-events/stats|timeseries|offers` (TrackingEventController)

The genuinely **missing** piece — and exactly where the "long text / large files" test requirements live — is **Content**. So I build Content and document + test the whole flow.

## Design (revised — build only what's missing)

### New: Content
- **Migration** `contents`: `user_id(FK), offer_id(nullable FK→tracking_events.id, set null), title, body(LONGTEXT), media_path, media_filename, media_mime, media_size, status(draft|published)`.
- **Model** `Content` (HasFactory): belongsTo User, belongsTo Offer.
- **Relations added**: `User::contents()`, `Offer::contents()`.
- **ContentFactory**.
- **ContentController** (Scribe-annotated): `apiResource contents` (owner-scoped) + multipart media upload on store/update + `GET /contents/{id}/media` stream download. Long `body` = long-text path; uploaded media = large-file path.

### Endpoints (new)
- `GET/POST /api/contents`, `GET/PUT/DELETE /api/contents/{id}` (auth, owner-scoped)
- `GET /api/contents/{id}/media` (auth) — download stored media

### Reused (already exist — documented + integration-tested, not rebuilt)
login, tracking-events (offers), tracking-links, track/conversion, track/click, stats/timeseries.

### Scribe / Swagger docs
Scribe auto-documents ALL `api/*` routes (introspects validation), so the existing flow is covered for free. Rich `@group/@authenticated/@bodyParam/@response` annotations on the new Content endpoints. Run `php artisan scribe:generate` → HTML `/docs`, OpenAPI `/docs.openapi`, Postman `/docs.postman`.

### Dropped from original plan
No parallel `offers`/`offer_links`/`conversions` tables (would duplicate existing `tracking_events`/`tracking_links`/`tracking_conversions`). Original migration rewritten to `contents` only.

## Tests — fully tested
**tests/Feature/ContentApiTest.php** (the new resource):
- [ ] Auth required (401) on content endpoints
- [ ] Content CRUD + ownership isolation (can't see/edit another user's content)
- [ ] Validation errors (422) — missing title, bad status, oversize/bad-mime media
- [ ] **Very long text body (≥100k chars)** persists & returns intact
- [ ] **Large file upload (multi-MB UploadedFile::fake)** stores, size/mime recorded, downloadable
- [ ] Content attached to an Offer (offer_id) round-trips

**tests/Feature/OfferFlowTest.php** (end-to-end, existing endpoints):
- [ ] login → create offer → create link → record conversion → analytics reflects it
- [ ] post content tied to the offer appears in flow

## Steps
1. Migration `contents` only (rewrite existing file)
2. `Content` model + `User::contents()` + `Offer::contents()`
3. `ContentFactory`
4. `ContentController` (Scribe-annotated) + routes in `api.auth` group
5. Feature tests (long-text + large-file + flow) → `php artisan test`
6. `php artisan scribe:generate` → verify `/docs.openapi` includes content + flow
7. Review + document results here

## Review (completed)

### What shipped
- **Migration** `2026_06_16_120000_create_contents_table.php` — `contents` table (longText `body`, media columns, nullable `offer_id` FK → `tracking_events`).
- **Model** `app/Models/Content.php` (HasFactory, hides `media_path`, `hasMedia()` helper).
- **Relations** `User::contents()`, `Offer::contents()`.
- **Factory** `database/factories/ContentFactory.php` (+ `published()` state).
- **Controller** `app/Http/Controllers/ContentController.php` — full CRUD, multipart media upload (≤50 MB, private `local` disk), streamed media download, owner-scoping, Scribe annotations.
- **Routes** `apiResource('contents')` + `GET contents/{id}/media` under `api.auth`.
- **Tests** `tests/Feature/ContentApiTest.php` (11) + `tests/Feature/OfferFlowTest.php` (1) — **12 passing, 63 assertions**. Covers auth, CRUD, ownership isolation, validation, **200k-char body**, **12 MB upload + download**, oversize rejection, offer attachment, and the **full login→offer→link→content→conversion→analytics** flow.
- **Docs** `php artisan scribe:generate` → OpenAPI `storage/app/private/scribe/openapi.yaml`, Postman collection, HTML at `/docs` (`/docs.openapi`, `/docs.postman`). New **Content** group documented; existing login/offers/links/analytics auto-documented.

### Decisions / discoveries
- Discovered an in-flight, uncommitted refactor renaming `TrackingEvent` → `Offer`. Built **on** it: only `Content` was genuinely missing. Did **not** create duplicate offer/link/conversion tables (original plan revised).
- `tracking_events.conversion_url` was dropped by the refactor — test offer helper adjusted accordingly.
- **`phpunit.xml`**: pointed the auxiliary `outlier_db` connection at sqlite for `APP_ENV=testing` only, so the suite runs without an external Postgres host (previously every test errored at migration). No production impact.

### Known pre-existing failures (NOT caused by this work)
Running the full suite surfaced 6 failures in `ApiTest` (`/api/thumbnails`, `/api/titles/generate-from-project`) and `ViewsMaxApiTest` (onboarding). These hit unrelated endpoints, reference nothing I added, and were already broken on this WIP branch — only now visible because the suite can run. Worth a separate look, outside this task.

### Follow-ups (optional)
- Scribe logs `pgsql`-connection errors during generation (it attempts live response-calls against a non-running Postgres). Pre-existing; could remove `pgsql` from `databaseConnectionsToTransact` in `config/scribe.php` to silence.
# Social Media Publishing — Backend

Connect and publish to **Facebook, Instagram, Threads, LinkedIn, Bluesky, X,
TikTok, YouTube, and Google My Business**.

The backend is **complete and verified** (migrations run, providers resolve,
routes registered, token encryption + status-sync tested). What's left is the
part only you can do: register OAuth apps and paste credentials in. Then test
the live connect flow.

---

## ✅ What's done (backend)

- [x] `social_accounts`, `social_posts`, `social_post_targets` tables
- [x] Models with encrypted-at-rest tokens (`SocialAccount`, `SocialPost`, `SocialPostTarget`)
- [x] Provider abstraction + 9 platform providers (OAuth + publish for each)
- [x] `SocialProviderManager` (resolve by platform, config/enabled checks)
- [x] `SocialAccountController` — list platforms, connect (OAuth + credentials), disconnect
- [x] `SocialPostController` — create/publish/schedule/retry, list, show
- [x] `PublishSocialPostJob` — async per-target publishing with retries
- [x] API routes under `/api/social/*`
- [x] `config/social.php` wired to env vars

---

## TODO — FOR YOU (Sean): register OAuth apps + add env vars

Add these to `.env` (and your production secrets). Each platform is **disabled
in practice until its `*_CLIENT_ID` / `*_CLIENT_SECRET` are present** — the API
returns a clear "not configured" 503 until then, so you can roll them out one at
a time.

> Set **one** callback/redirect URL per platform in each developer console.
> Use your frontend callback, e.g. `https://app.yourdomain.com/social/callback`
> and set `FRONTEND_URL` so the default is derived automatically.

```dotenv
# Frontend base URL — used to build the default OAuth redirect_uri
FRONTEND_URL=https://app.yourdomain.com

# --- Facebook + Instagram (same Meta app) ---
# https://developers.facebook.com/apps  →  add "Facebook Login" + "Instagram Graph API"
FACEBOOK_CLIENT_ID=
FACEBOOK_CLIENT_SECRET=
FACEBOOK_GRAPH_VERSION=v21.0

# --- Threads (separate Meta "Threads API" app) ---
# https://developers.facebook.com/  →  create app with "Threads API" use case
THREADS_CLIENT_ID=
THREADS_CLIENT_SECRET=

# --- LinkedIn ---
# https://www.linkedin.com/developers/apps  →  request "Share on LinkedIn" + "Sign In with OpenID"
LINKEDIN_CLIENT_ID=
LINKEDIN_CLIENT_SECRET=

# --- X / Twitter (OAuth 2.0, with PKCE) ---
# https://developer.x.com/  →  app with OAuth2, scopes incl. tweet.write + offline.access + media.write
X_CLIENT_ID=
X_CLIENT_SECRET=

# --- TikTok ---
# https://developers.tiktok.com/  →  Login Kit + Content Posting API (video.publish)
TIKTOK_CLIENT_KEY=
TIKTOK_CLIENT_SECRET=

# --- Bluesky --- (no app to register; users connect with handle + app password)
BLUESKY_SERVICE_URL=https://bsky.social

# --- YouTube + Google My Business (reuse existing Google OAuth app) ---
# Already have GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET for YouTube OAuth.
# In Google Cloud Console, ADD these scopes/APIs to the SAME OAuth consent screen:
#   - YouTube Data API v3  (youtube.upload)
#   - Business Profile APIs (business.manage)  ← request access, it needs approval
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=
```

### Per-platform app checklist

| Platform | Console | Key scopes/permissions | Notes / review needed |
|---|---|---|---|
| Facebook | developers.facebook.com | `pages_manage_posts`, `pages_read_engagement`, `pages_show_list`, `business_management` | App Review required for these scopes before non-test users. Posts go to a **Page** (not personal profile). |
| Instagram | (same Meta app) | `instagram_basic`, `instagram_content_publish` | Needs an IG **Business/Creator** account linked to a FB Page. Media must be a **public URL**. |
| Threads | developers.facebook.com (Threads API) | `threads_basic`, `threads_content_publish` | Separate app from the main Meta app. |
| LinkedIn | linkedin.com/developers | `w_member_social`, `openid`, `profile`, `email` | "Share on LinkedIn" product must be approved. Posts as the member. |
| X | developer.x.com | `tweet.write`, `users.read`, `offline.access`, `media.write` | Use OAuth2 **Confidential** client. PKCE handled by backend. |
| TikTok | developers.tiktok.com | `video.publish`, `video.upload`, `user.info.basic` | Content Posting API needs audit/approval. **Video required** (or photo carousel). New posts default to `SELF_ONLY` until your app is audited. |
| Bluesky | — | — | No registration. User generates an **app password** at bsky.app → Settings → App passwords. |
| YouTube | console.cloud.google.com | `youtube.upload` | Publishing = **uploading a video**. Uploads default to `private`. |
| Google My Business | console.cloud.google.com | `business.manage` | Business Profile API access requires a Google approval form. One connected account **per location**. |

### Then test the live connect flow

1. `php artisan migrate` (runs the 3 new tables).
2. Make sure a queue worker is running (`php artisan queue:work`) — publishing is async.
3. `GET /api/social/platforms` → confirm the platforms you configured show `"configured": true`.
4. For an OAuth platform: `GET /api/social/{platform}/auth-url` → open the URL → approve → your
   frontend gets `?code=...&state=...` → `POST /api/social/{platform}/exchange` with that code.
5. `GET /api/social/accounts` → the account should appear.
6. `POST /api/social/posts` with `account_ids` → check `GET /api/social/posts/{id}` for per-target status.

---

## ⚠️ Notes / decisions to confirm

- **Default privacy is conservative**: YouTube uploads are `private`, TikTok is `SELF_ONLY`.
  Flip these in `YouTubeProvider`/`TikTokProvider` once you've confirmed the flow and passed audits.
- **Media must be publicly reachable URLs** (Instagram, Threads, TikTok, GMB fetch server-side).
  If you store media privately (S3), generate temporary public/pre-signed URLs before posting.
- **No credit gating** was added to the social routes. If posting should consume credits like
  other features, add the `check.credits` middleware (see `routes/api.php` patterns).
- **Scheduling** uses `dispatch()->delay()`. For long-horizon scheduling consider a scheduled
  command that sweeps due posts instead, but the delay approach works out of the box.

---

## Review (backend implementation)

- Followed the existing `YouTubeOAuthController` / service patterns (Laravel 12, Sanctum `api.auth`).
- Tokens encrypted at rest via Eloquent `encrypted` casts; hidden from API responses.
- One `SocialAccount` row per real account so multi-page / multi-location / multi-profile works
  (e.g. all your Facebook Pages connect at once from a single OAuth grant).
- Publishing isolated per target (one job each) so one platform failing never blocks the others;
  `POST /api/social/posts/{id}/retry` re-queues only the failed ones.
- Verified locally: migrations, provider container resolution (all 9), route registration,
  token encryption/decryption, JSON hiding, and post status-sync logic.

See `README/SOCIAL_PUBLISHING.md` for the full API contract.

---

# Automations — Instagram comment / story-reply / DM auto-responders (2026-09-09)

Plan: ~/.claude/plans/i-need-a-feature-goofy-glacier.md. Docs: README/SOCIAL_PUBLISHING.md → "Automations".

- [x] Config flag + scopes (`INSTAGRAM_AUTOMATIONS_ENABLED`), `SocialAccount::hasScopes/canRunAutomations`, granted `permissions` stored on connect
- [x] Dedicated `automations` log channel + file + `AUTOMATIONS_LOG_ENABLED` off-switch, `AutomationLog` wrapper
- [x] Migrations: `automations`, `automation_events`, `automation_runs`; `plans.max_automations`, `social_accounts.webhook_subscribed_at`, `short_links.automation_id/automation_run_id`; PlanSeeder limits
- [x] Provider: `subscribeWebhooks`, `listMedia`, `replyToComment`, `sendMessage`, `sendPrivateReply` + `MessageResult`
- [x] Webhook intake `/api/webhooks/instagram` (verify + signed receive + ledger)
- [x] Matcher (post filter, keyword modes, thread replies, cooldown, priority) + inbound job
- [x] Executor job (reply → DM card/text, per-run tracked links, reauth / window / rate-limit handling), redirect marks `clicked_at`
- [x] Subscription service + `automations:ensure-subscriptions` / `automations:prune-events` scheduled, re-subscribe after reconnect
- [x] CRUD API `/api/automations` with plan cap, reconnect hint, media picker cache
- [x] MCP tools (7) + discovery + API-key allowlist
- [x] Frontend: index, trigger picker, editor (post picker, keywords, reply, DM composer, phone preview, runs), routes/nav/titles/i18n
- [x] Docs + env vars
- [ ] Ops (outside the code): Meta App Review for the two scopes, register the webhook URL + verify token, flip the flag, reconnect Instagram accounts
- [ ] Phase 2: TikTok comment automations via TikTok Business API polling

## Review
Backend: 66 automation tests + 7 MCP/discovery tests green; full suite 725 passed, 7 pre-existing failures (ApiTest error-shape checks, ShockingTruthsHookSeederTest, ViewsMaxApiTest onboarding copy) unrelated to this work. Frontend: `npm run build` green, Vitest helpers spec green, no new type errors.

New env vars: INSTAGRAM_AUTOMATIONS_ENABLED, INSTAGRAM_WEBHOOK_VERIFY_TOKEN, INSTAGRAM_WEBHOOK_SIGNATURE_CHECK, AUTOMATIONS_LOG_ENABLED, AUTOMATIONS_LOG_LEVEL, AUTOMATIONS_LOG_DAYS, LIMIT_*_AUTOMATIONS.
