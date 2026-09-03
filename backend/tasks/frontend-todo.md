# Frontend TODO — Social Accounts & Publishing

The backend exposes everything below under `/api/social/*`. All routes require the
standard `Authorization: Bearer <token>` (Sanctum) header, same as the rest of the app.

There are **two screens** to build:

1. **Connections** — connect/disconnect each platform.
2. **Composer** — write a post, pick accounts, publish/schedule, show per-platform status.

---

## 1. Connections screen

### List supported platforms (which are configured)
```
GET /api/social/platforms
→ { success, data: [ { platform, label, enabled, configured, uses_oauth } ] }
```
Render a card per platform. If `configured === false`, show "Coming soon"/disabled
(the backend hasn't been given API keys for it yet). `uses_oauth === false` means
credential form (Bluesky), not a redirect.

### List the user's connected accounts
```
GET /api/social/accounts            (optional ?platform=facebook)
→ { success, data: [ {
      id, platform, platform_label, name, username, avatar_url, profile_url,
      status, token_valid, token_expires_at, last_error, last_synced_at, connected_at
   } ] }
```
`status` is one of `connected | needs_reauth | revoked | error`. If `needs_reauth`,
prompt the user to reconnect.

### Connect an OAuth platform (Facebook, Instagram, Threads, LinkedIn, X, TikTok, YouTube, Google My Business)

**Step 1 — get the authorization URL:**
```
GET /api/social/{platform}/auth-url?redirect_uri=https://app.you.com/social/callback
→ { success, data: { authorization_url, state, redirect_uri } }
```
- `redirect_uri` is optional if `FRONTEND_URL` is set on the backend; otherwise pass it.
- **Persist `state`** (and the `platform`) in `sessionStorage` so you can verify it on return.
- Redirect the browser (or open a popup) to `authorization_url`.

**Step 2 — handle the callback** at your `redirect_uri`. The provider returns
`?code=...&state=...`. Verify `state` matches what you stored, then:
```
POST /api/social/{platform}/exchange
body: { code, state, redirect_uri }      // redirect_uri only needed if you passed one in step 1
→ { success, message, data: [ <SocialAccountResource>, ... ] }
```
Note: **one connect can return multiple accounts** (e.g. all Facebook Pages, all
Instagram accounts, all Google Business locations). Show them all.

> Tip: a single popup callback page can read `platform` + `state` from
> `sessionStorage`, call exchange, then `postMessage` the result back to the opener.

### Connect Bluesky (no OAuth — handle + app password)
```
POST /api/social/bluesky/connect
body: { identifier: "you.bsky.social", password: "xxxx-xxxx-xxxx-xxxx" }   // app password
→ { success, message, data: [ <SocialAccountResource> ] }
```
Show a short note: "Create an app password at bsky.app → Settings → App passwords."

### Disconnect
```
DELETE /api/social/accounts/{id}
→ { success, message }
```

---

## 2. Composer screen (create + publish)

### Publish (or schedule) a post
```
POST /api/social/posts
body: {
  content: "Caption / text body",          // required unless media is provided
  link: "https://...",                      // optional (LinkedIn/Bluesky/GMB CTA)
  media: [                                  // optional; must be PUBLIC urls
    { url: "https://.../img.jpg", type: "image", alt: "..." },
    { url: "https://.../clip.mp4", type: "video" }
  ],
  account_ids: [1, 4, 7],                   // ids from GET /api/social/accounts
  scheduled_at: "2026-06-20T14:00:00Z"      // optional; omit to publish now
}
→ 201 { success, message, data: <SocialPostResource with targets[]> }
```

**Per-platform rules to surface in the UI** (validate before sending so users aren't surprised):
- **Instagram** — requires at least one image or video.
- **TikTok** — requires a video (or photo carousel); text-only will fail.
- **YouTube** — requires a video; `content` becomes the title/description.
- **X** — 280 chars; images supported.
- Others accept text-only.

Because different platforms have different rules, a post can **partially succeed**.

### Poll / show status
```
GET /api/social/posts/{id}
→ { success, data: {
     id, content, media, link, status, scheduled_at, published_at, created_at,
     targets: [ { id, social_account_id, platform, status, remote_post_id,
                  remote_post_url, error, published_at } ]
   } }
```
- Post `status`: `queued | publishing | published | partial | failed | scheduled`.
- Target `status`: `pending | publishing | published | failed | skipped`.
- Publishing is async — poll this endpoint (every few seconds) until no target is
  `pending`/`publishing`. Show each platform's `error` on failure and link to
  `remote_post_url` on success.

### List past posts
```
GET /api/social/posts?per_page=20         (paginated)
```

### Retry failed targets
```
POST /api/social/posts/{id}/retry
→ { success, message }   // re-queues only the FAILED targets
```

---

## Suggested UX notes
- Show a per-account toggle in the composer; group toggles by platform with the avatar.
- Disable the publish button until at least one account is selected and the content
  satisfies the strictest selected platform's rule (e.g. video required for TikTok/YouTube).
- For `needs_reauth` accounts, show an inline "Reconnect" button that re-runs the OAuth flow.
- Media uploads: the backend expects **public URLs**. Upload to your existing media/S3
  flow first, then pass the resulting public URL(s) in `media[]`.
