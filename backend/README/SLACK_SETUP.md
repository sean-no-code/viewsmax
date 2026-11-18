# Slack — app setup and testing

Slack posting is off until `SLACK_CLIENT_ID` and `SLACK_CLIENT_SECRET` are set:
without them Slack shows "Coming soon" on Connections and is left out of the
composer and the AI tools. `SLACK_ENABLED=false` switches it off with keys set.

This is a separate Slack app from the error-logging bot
(`SLACK_BOT_USER_OAUTH_TOKEN`, `LOG_SLACK_WEBHOOK_URL` — see `SLACK_ERROR_LOGGING.md`).

## How it works

- One connected account = one channel in one workspace. Connecting asks Slack for
  the `incoming-webhook` scope, so **Slack's own consent screen asks the user which
  channel** the app may post to. Connecting again (Connections → Slack → "Add
  channel") adds another channel.
- Posts go out with the workspace's **bot token** (`chat.postMessage`, or Slack's
  external file upload for images/video), so each post gets a message link. The
  webhook URL Slack also returns is not used or stored.
- All channels of a workspace share one bot token. Disconnecting a channel revokes
  the token (`auth.revoke`) only when it was the workspace's last connected channel
  (counting every ViewsMax user).

## 1. Create the Slack app

1. Go to https://api.slack.com/apps → **Create New App** → **From scratch**. Name it
   (e.g. "ViewsMax") and pick a development workspace you own. This name is what
   users see in Slack and type after `/invite`.
2. **Basic Information → Display Information**: icon, short description, colour.
3. **OAuth & Permissions → Redirect URLs** — add every one that applies. Slack only
   accepts **HTTPS** redirect URLs, and the one sent must match or sit under one listed:
   - `https://viewsmax.com/oauth/callback` (website connect flow; the frontend sends `{origin}/oauth/callback`)
   - `https://api.viewsmax.com/connect/slack/callback` (sign-up flow from Claude/ChatGPT)
   - local testing needs an HTTPS tunnel URL (e.g. `https://<tunnel>/oauth/callback`); `http://localhost` is refused.
4. **OAuth & Permissions → Scopes → Bot Token Scopes** — add exactly these
   (also in `config/social.php`):
   - `incoming-webhook` — the channel picker on the consent screen
   - `chat:write` — post messages
   - `chat:write.public` — post in a public channel the app hasn't joined
   - `files:write` — upload images and video

   No **User Token Scopes**.
5. **OAuth & Permissions → Advanced token security via token rotation**: leave it
   **off**. With rotation on, bot tokens expire after 12 hours and need a refresh flow
   ViewsMax doesn't have; the connect step refuses such an app with a clear error.
6. **Incoming Webhooks**: check that **Activate Incoming Webhooks** is on (adding the
   `incoming-webhook` scope should switch it on).
7. **Basic Information → App Credentials**: copy the **Client ID** and **Client
   Secret** into the backend environment:

```
SLACK_CLIENT_ID=
SLACK_CLIENT_SECRET=
# optional, default true
SLACK_ENABLED=true
```

At this point the app can be installed into its own development workspace, which is
enough to test the whole flow.

## 2. Letting other workspaces install it

- **Manage Distribution → Activate Public Distribution** (complete Slack's checklist:
  HTTPS redirect URLs, no hard-coded tokens). Without it, only the development
  workspace can install the app. Slack does not review this step.
- **Slack Marketplace.** Slack's May 2025 terms update says the Marketplace (listed
  or unlisted) is the only appropriate channel for *commercially* distributing a Slack
  app, and that apps distributed outside it are for development and testing. For
  ViewsMax as a paid product, plan to submit the app to the Marketplace, which means
  Slack's review (security review included). See
  https://docs.slack.dev/changelog/2025/05/29/rate-limit-changes-for-non-marketplace-apps.
  The rate-limit cut in that change only affects `conversations.history` /
  `conversations.replies`, which ViewsMax does not call.
- Workspaces can require an admin to approve new apps; their users then see
  Slack's "request to install" screen instead of the channel picker.

## Behaviour (what the code guarantees)

- The channel is always the one the user picked on Slack's screen. A post is never
  sent to a different channel; with several Slack channels connected, publishing by
  platform alone (no channel chosen) is refused (composer, `POST/PUT /api/posts`, AI tools).
- Captions are sent as plain text with Slack's three control characters escaped
  (`&` → `&amp;`, `<` → `&lt;`, `>` → `&gt;`), so a caption can't ping
  `@channel`/`@here`/`@everyone` or forge a link. Slack's `*bold*` / `_italic_`
  markup still applies.
- Caption limit 4,000 characters (Slack's recommended `chat.postMessage` maximum).
  Up to 10 images/videos per post, sent as one message with the caption.
- Private channel the app isn't in (`not_in_channel` / `channel_not_found`): the post
  fails with "add the app to the channel (`/invite`)". Archived channel, workspace
  restrictions, refused file types and too-long text each get their own message.
- `invalid_auth`, `token_revoked`, `account_inactive`, `missing_scope`: the account is
  marked for reconnect (the user gets the reconnect email). This also happens when a
  workspace removes the app, or — Slack's rule for apps with scopes beyond
  `incoming-webhook` — when the user who installed it leaves the workspace.
- Rate limits (HTTP 429 / `ratelimited`, `rate_limited`) and Slack outages are
  retried by the queue, after Slack's `Retry-After` when it sends one.
  `internal_error` / `fatal_error` are not retried automatically (Slack says the post
  may have gone through).
- Message link: `chat.getPermalink` for the posted message. For a file post, when
  Slack's upload response doesn't yet include the share, the first file's id and
  link are stored instead.

## What only a real Slack app can confirm

None of this has been run against Slack yet (no app credentials existed when it was
built); the tests use recorded-shape fakes. Check on first install:

- the consent screen shows the channel picker and the `incoming_webhook` block comes
  back with `channel_id`;
- whether the bot is added to a **private** channel picked on that screen (if not,
  the first post fails with the `/invite` message — then invite and retry);
- that `files.completeUploadExternal` returns the share's `ts` (message link) or only
  the file link;
- that a caption with `<!here>` posts literally and pings no one.
