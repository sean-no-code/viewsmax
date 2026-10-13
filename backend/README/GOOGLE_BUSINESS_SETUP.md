# Google Business Profile — setup and testing

Google Business Profile posting uses the same Google OAuth app as YouTube
(`GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`), so its keys are always set. It stays
off until `GOOGLE_BUSINESS_ENABLED=true`: until then it shows "Coming soon" on
Connections and is left out of the composer, the AI tools and `/api/ai`.

## 1. Request API access (Google review)

From https://developers.google.com/my-business/content/prereqs:

- Google needs a Business Profile that has been **verified and active for 60+ days**
  (ours or a client's) and a **website** for that business.
- Submit https://support.google.com/business/contact/api_default and pick
  **"Application for Basic API Access"**. It asks for the Google Cloud **project
  number** (Dashboard → Project info) of the project that holds `GOOGLE_CLIENT_ID`.
  Send it from an email that is an owner or manager on that Business Profile.
- Until Google approves, the project's Business Profile quota is **0 requests per
  minute** and every call fails (Cloud console → APIs → Quotas shows 0; 300 once approved).

## 2. After approval

From https://developers.google.com/my-business/content/basic-setup, in the same Cloud project:

- **Enable** "Google My Business API" (only visible after approval),
  "My Business Account Management API" and "My Business Business Information API".
  These three are the ones ViewsMax calls.
- **OAuth consent screen**: add the scope `https://www.googleapis.com/auth/business.manage`.
- **Credentials → the OAuth client → Authorized redirect URIs**: make sure these exist:
  - `https://viewsmax.com/oauth/callback` (website connect flow; the frontend sends `{origin}/oauth/callback`)
  - `https://api.viewsmax.com/connect/google_business/callback` (sign-up flow from Claude/ChatGPT)
- Set `GOOGLE_BUSINESS_ENABLED=true` and clear the config cache.

## 3. First live test

Connect a Google account that manages a verified Business Profile on Connections, then
publish one update, one event and one offer to it. Things only the real API can confirm:
Google's consent screen, which photo URLs Google accepts, real error messages, and how
long a post stays in `PROCESSING` before it is `LIVE`.

## Behaviour (what the code guarantees)

- Every location the Google account manages is connected as its own account; locations
  Google marks as unable to take posts (`metadata.canOperateLocalPost = false`) are skipped.
- Post types: **Update** (`STANDARD`), **Event**, **Offer**. Events and offers need a title,
  a start and an end, sent as the business's local wall-clock time
  (`options.google_business.event_start` / `event_end`, `YYYY-MM-DDTHH:MM`).
- Button (`cta_type`): Book, Order online, Buy, Learn more, Sign up, Call now, or none.
  Every button except Call needs a link. Google ignores buttons on offers.
- Only the first **image** is sent; videos are not.
- Event times sent with seconds or a space (`2026-11-06 18:00:00`) are stored as `2026-11-06T18:00`.
- AI tools: `create_post` takes `social_account_ids` to post to chosen locations. With several
  locations connected, `platforms: ["google_business"]` is refused with the list of locations,
  so the AI asks the user instead of posting to whichever was connected last.
- The post's language is the location's own `languageCode`.
- Caption limit 1,500 characters. Google's docs don't state a number; 1,500 is what
  third-party API docs report. Check it in the first live test.
- Tokens are refreshed 5 minutes before they expire. A reconnect is only asked for when
  Google rejects the grant (400/401/403), not on an outage.

## Contract test against Google's own API description (no approval needed)

`tests/Feature/GoogleBusinessContractTest.php` runs the real provider against a local
mock built by `tests/contract/google-business/build-spec.php` from Google's discovery
documents, and fails on any request that breaks Google's schema. Instructions are in the
test's header. Skipped by default.

For a local walkthrough against that mock, set in `backend/.env`:

```
GOOGLE_BUSINESS_ENABLED=true
GOOGLE_BUSINESS_API_BASE=http://127.0.0.1:4020
```

and connect the demo locations with
`GoogleBusinessProvider::storeLocations($user, new OAuthResult('demo-token', 'demo-refresh', 3600))`
in tinker (the Google login itself can't be mocked).
