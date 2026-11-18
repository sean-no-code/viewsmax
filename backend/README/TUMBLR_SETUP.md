# Tumblr — app setup and limits

Tumblr posting is off until `TUMBLR_CLIENT_ID` and `TUMBLR_CLIENT_SECRET` are set:
without them Tumblr shows "Coming soon" on Connections and is left out of the
composer and the AI tools. `TUMBLR_ENABLED=false` hides it even with keys set.

Official docs: https://www.tumblr.com/docs/en/api/v2 (source:
https://github.com/tumblr/docs/blob/master/api.md) and the Neue Post Format spec
https://www.tumblr.com/docs/npf (`npf-spec.md` in the same repo).

## 1. Register the app

1. Log in to Tumblr with the account that should own the app and open
   https://www.tumblr.com/oauth/apps → **Register application**.
2. Fill in the name, website (`https://viewsmax.com`), description and contact email.
3. Edit the app and add the **OAuth2 redirect URLs** — Tumblr only enables OAuth2 for
   an app once one is set:
   - `https://viewsmax.com/oauth/callback` (website connect flow; the frontend sends `{origin}/oauth/callback`)
   - `https://api.viewsmax.com/connect/tumblr/callback` (sign-up flow from Claude/ChatGPT)
   - `http://localhost:8080/oauth/callback` (local testing, optional)

   With more than one URL registered, Tumblr requires `redirect_uri` on the authorize
   request; ViewsMax always sends it.
4. Copy the **OAuth Consumer Key** and **Secret Key** into `.env`:

```
TUMBLR_CLIENT_ID=      # OAuth Consumer Key
TUMBLR_CLIENT_SECRET=  # Secret Key
# TUMBLR_ENABLED=true  # default; false hides Tumblr everywhere
```

The API docs describe no app review step for posting to the connecting user's own
blogs; only the rate limits below apply.

Scopes requested on connect: `basic write offline_access` (`config/social.php`).
`offline_access` is what makes Tumblr return a refresh token; a connect whose token
lacks `write` or `offline_access` is refused with a "reconnect and tick every box" error.

## 2. Rate limits — a new app is capped

From the API docs' "Rate Limits" section (a hit returns HTTP 429):

| Limit | Value |
|---|---|
| API calls per consumer key (the whole app, all users) | **1,000 per hour, 5,000 per day** |
| New published posts per user (incl. reblogs) | 250 per day |
| Images uploaded per user | 250 per day |
| Videos uploaded per user | 20 per day, 60 minutes total per day |
| Per IP address | 300 per minute, 18,000 per hour, 432,000 per day |

Daily limits are per calendar day. The per-consumer-key limits are the ones a
growing ViewsMax will hit first: every publish is one call, plus a token refresh
about every 40 minutes per connected Tumblr login while it is used.

**Getting them lifted** (https://help.tumblr.com/developer-resources/): go to
https://www.tumblr.com/oauth/apps and click **Request rate limit removal** under the
app; it opens an email to `api_ratelimit@tumblr.com`. Do this before opening Tumblr to
all users.

## Behaviour (what the code guarantees)

- **One login, every blog.** Connecting reads `GET /v2/user/info` and stores one account
  per blog the user can post to (the blog's `uuid` is the account id, its name the
  handle). All of them share one token pair, tagged with the same `metadata.grant_id`.
- **Tokens.** Access tokens last about 40 minutes (`expires_in` 2520 in Tumblr's
  example); they are refreshed 5 minutes before expiry at publish time and by the daily
  `social:refresh-tokens`. A refresh writes the new access *and* refresh token to every
  blog of the grant, under one lock per grant, so two jobs for sibling blogs never both
  spend the same refresh token. A refused refresh flags every blog of the grant for
  reconnect (one email); a 429/5xx leaves them connected and the publish is retried.
- **Posts** go out as Neue Post Format (`POST /v2/blog/{uuid}/posts`): media blocks
  first, then one text block per paragraph. Captions are capped at 4,096 characters
  (NPF's per-text-block limit). Up to 30 images and 1 video per post (NPF limits).
- **Media** is downloaded through `SafeHttp` and uploaded in the same request as
  `multipart/form-data` (a `json` part plus one part per file, matched by
  `identifier`), as the docs' "User Uploaded Media" section describes.
- **Tags** (`options.tumblr.tags`, a list) are the only option, sent as Tumblr's
  comma-separated string and only when the user typed some. A leading `#` is dropped.
- **Video** is accepted while Tumblr is still transcoding it: the 201 with the post id
  counts as published. If an earlier video on the account is still transcoding, Tumblr
  refuses the next one (403, code 8010); the job retries after 1 and then 5 minutes.
- **Errors:** 401 → the grant is flagged for reconnect; 403 → "can't post to this blog"
  (daily post/media/video limits and a full queue get their own message); 404 → blog
  gone; 400 → Tumblr's own reason passed through; 429/5xx → retried, then "Tumblr is
  limiting how many requests ViewsMax can make". Every final failure is logged at error
  level with Tumblr's reason and any `X-Ratelimit-*` headers.

## Not verified against the live API

No Tumblr app existed when this was built; every request is checked with
`Http::fake` (`tests/Feature/TumblrPublishTest.php`). To confirm on a real app:
the consent screen and returned scopes, the `user/info` blog fields (`uuid`, `title`,
`avatar`), a multipart image and video post, the real error bodies (`errors[].code`),
and the rate-limit header names.
