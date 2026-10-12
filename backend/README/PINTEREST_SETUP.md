# Pinterest — app setup and testing

Pinterest posting is off until `PINTEREST_CLIENT_ID` and `PINTEREST_CLIENT_SECRET`
are set: without them Pinterest shows "Coming soon" on Connections and is left
out of the composer, the AI tools and `/api/ai`.

## 1. Create the Pinterest app (Trial access)

From Pinterest's guide (developers.pinterest.com/docs/getting-started/connect-app/):

1. Log in to, or create, a **business** account at https://www.pinterest.com/business/create/.
   This account owns the app.
2. Verify the account's email address.
3. Open https://developers.pinterest.com/apps/ and accept the Developer Terms of Service.
4. Click **Connect app**, fill in the request form and submit it for **Trial access**.
   The form needs a privacy policy link (Pinterest developer guidelines):
   `https://viewsmax.com/privacy/`.
5. Requests are reviewed each business day; the answer comes by email.

## 2. After approval

In **My apps → your app → Configure**:

- **Redirect URIs** — add all that apply:
  - `https://viewsmax.com/social/callback` (website connect flow)
  - `https://api.viewsmax.com/connect/pinterest/callback` (sign-up flow from Claude/ChatGPT)
  - `http://localhost:8080/social/callback` (local testing, optional)
- Copy the **App ID** and **App secret** into `.env`:

```
PINTEREST_CLIENT_ID=
PINTEREST_CLIENT_SECRET=
PINTEREST_SANDBOX=true
```

Scopes requested on connect: `user_accounts:read, boards:read, pins:read, pins:write`
(`config/social.php`).

## 3. Testing on Trial

- With Trial access, Pins are visible only to the app owner.
- `PINTEREST_SANDBOX=true` sends every call to `api-sandbox.pinterest.com`. The sandbox
  cannot create **video** Pins; set it to `false` to test video on Trial.
- Things only the real API can confirm: the Pinterest login page, the video upload to
  Pinterest's media storage, real error messages and Trial limits.

## 4. Standard access (public posting)

Upgrade from the app card in My apps. Pinterest requires a video of the app using the
API, showing the OAuth (login) flow and a live integration — e.g. connecting an
account in ViewsMax and publishing a Pin.

## Behaviour (what the code guarantees)

- The board is always chosen by the user, per Pinterest account; publishing without one
  is refused (composer, `POST/PUT /api/posts`, AI tools).
- Title, link and alt text are sent exactly as typed; empty means the Pin has none.
- A video Pin needs a cover image (`options.pinterest.cover_url`).
- Boards are fetched live (`GET /api/social/accounts/{id}/boards`) and never stored —
  Pinterest's developer guidelines forbid storing API data.
- Tokens: access token 30 days; refreshed in its last week by `social:refresh-tokens`
  (daily), which also keeps the 60-day refresh token alive.

## Contract test against Pinterest's official API spec (no account needed)

`tests/Feature/PinterestContractTest.php` runs the real provider against a local mock
generated from https://github.com/pinterest/api-description and fails on any request
that breaks the spec. Instructions are in the test's header. Skipped by default.
