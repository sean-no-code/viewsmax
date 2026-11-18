# VK: app setup and approval

VK posting stays off until `VK_CLIENT_ID` and `VK_SERVICE_TOKEN` are set. Without them,
VK shows "Coming soon" on Connections and is left out of the composer and the AI tools (MCP). `/api/ai` does not
name VK yet; that marketing text is handled in a separate follow-up.
`VK_ENABLED=false` turns it off even with credentials.

ViewsMax posts to the connected person's **own VK wall**: text, up to 10 photos and
videos, or both. Community walls are not supported yet.

## 0. Approval: read this first

VK hands out wall posting and photo/video uploads only on request.

- `wall.post` needs the `wall` access right. VK's method page says the right "is issued
  in exceptional cases by a request to support" at **devsupport@corp.vk.com**
  (https://dev.vk.ru/en/method/wall.post).
- `video.save` needs the `video` right, which is also issued only in exceptional cases
  through the same address (https://dev.vk.ru/en/method/video.save).
- The VK ID docs split access into *basic* access (available as soon as the app exists)
  and *extended* access. Extended access needs a confirmed business profile. They name
  friends, video and photos as extended access granted "in exceptional cases", again
  through devsupport@corp.vk.com
  (https://id.vk.ru/about/business/go/docs/ru/vkid/latest/vk-id/connection/create-application).
  The VK ID scope list does not mention `wall` at all.
- New apps get 10,000 VK API calls a month; verified ones get 100,000,000 (same page).

So, before anyone can post:

1. Confirm the VK ID business profile (Russian resident or non-resident path, linked
   from the page above).
2. Email devsupport@corp.vk.com with the app ID. Ask for the **wall**, **photos** and
   **video** access rights for VK ID user tokens. Explain the use: a scheduler that posts
   the signed-in user's own content to their own wall, at a time they choose.
3. Once granted, turn the accesses on in the VK ID cabinet: app → **Доступы** →
   **Расширенные доступы**.

If VK grants only `wall`, remove `photos` and `video` from `scopes` in
`config/social.php`. Otherwise sign-in fails with `invalid_scope`, because an app may not
request more than its settings allow. Text posts will then work, and image or video posts
will fail with "VK refused permission to upload photos/videos".

## 1. Create the VK ID app

At https://id.vk.ru/business/go, create an app of type **Web** and make it
**confidential**: ViewsMax exchanges the code on its backend.

- **Trusted redirect URLs**: add each of these that applies:
  - `https://viewsmax.com/oauth/callback` (website connect flow; the frontend sends `{origin}/oauth/callback`)
  - `https://api.viewsmax.com/connect/vk/callback` (sign-up flow from Claude/ChatGPT)
  - `http://localhost:8080/oauth/callback` (optional, for local testing)
- **Base domain**: the site's domain. VK requires each redirect URL's domain to match it.
- **Keys**: copy the app ID and the **service token** (Сервисный ключ доступа) into `.env`:

```
VK_CLIENT_ID=
VK_SERVICE_TOKEN=
VK_ENABLED=true
```

`VK_SERVICE_TOKEN` is sent as `service_token`, which VK ID requires on every code
exchange and token refresh for a confidential app
(https://id.vk.ru/about/business/go/docs/ru/vkid/latest/vk-id/connection/api-description).
The cabinet also shows a "protected key" (`client_secret`). ViewsMax does not use it.

## 2. How it works (what the code does)

- **Sign-in**: VK ID OAuth 2.1 with PKCE. ViewsMax opens `https://id.vk.ru/authorize`
  with `response_type=code`, `code_challenge` (S256), a state of at least 32 characters
  and `scope=vkid.personal_info wall photos video`. The PKCE verifier stays on the server.
- **Callback**: VK redirects back with `code`, `state` and `device_id`. The frontend
  callback passes `device_id` to `POST /api/social/vk/exchange`. The API-host callback
  (`/connect/vk/callback`) reads it from the query string.
- **Token exchange**: `POST https://id.vk.ru/oauth2/auth` with `grant_type=authorization_code`,
  `code`, `code_verifier`, `device_id`, `redirect_uri`, `state`, `client_id` and
  `service_token`. The profile comes from `POST /oauth2/user_info`. If the token response
  lists scopes and `wall` is missing, the connect fails.
- **Tokens**: access tokens last one hour, and every refresh replaces both tokens. They
  are refreshed in their last 5 minutes, at publish time and by the daily
  `social:refresh-tokens`, with the `device_id` saved on the account (`metadata.device_id`).
  If VK refuses the refresh of an expired token, the account is set to needs-reconnect.
- **Posting** (VK API `https://api.vk.ru/method/*`, `v=5.199`, token as a Bearer header):
  - photo: `photos.getWallUploadServer` → multipart `photo` upload → `photos.saveWallPhoto`
  - video: `video.save` (`wallpost=0`) → multipart `video_file` upload to `upload_url`
  - `wall.post` with `owner_id` (the user), `message` and `attachments`
    (`photo{owner}_{id}`, `video{owner}_{id}`, at most 10).
  - The post link is stored as `https://vk.com/wall{owner}_{post_id}`.
- **Errors**: each VK error code gets its own message, and VK's code and text are logged
  at error level:
  5 → account set to needs-reconnect; 7/15 → permission refused (worded for wall, photos
  or video); 6 (after one short wait), 1, 10 and HTTP 429/5xx → the queue retries;
  9 → flood control; 29 → daily method limit; 14 → captcha; 17 → validation required;
  18 → page deleted/blocked; 214 → wall closed; 219/224 → advertising limits;
  222 → links not allowed.
- **Limits used**: 15,895 caption characters (VK publishes no wall.post text limit, so
  this is the figure commonly cited for a regular post); 10 attachments; photos as JPG,
  PNG or GIF up to 50 MB (VK's upload page).

## 3. What has not been checked against the live API

No VK credentials existed when this was built. Every VK call is covered by `Http::fake`
tests (`tests/Feature/VkPublishTest.php`) written from VK's documentation. None of it
has run against VK yet. Check these with a real app after approval:

- that VK ID grants `wall` / `photos` / `video` once support enables them, and that the
  token response's `scope` lists them under those names;
- that the service token is the value VK expects as `service_token`;
- the `user_info` response shape (the code reads `user.first_name`, `user.last_name`,
  `user.avatar`);
- whether a wall post accepts a video that VK is still processing;
- VK's real error codes for the cases above.
