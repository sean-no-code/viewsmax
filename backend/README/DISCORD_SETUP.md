# Discord — app setup and testing

Discord posting is off until `DISCORD_CLIENT_ID` and `DISCORD_CLIENT_SECRET` are
set: without them Discord shows "Coming soon" on Connections and is left out of the
composer and the AI tools. `DISCORD_ENABLED=false` hides it even with keys.

## How it works

ViewsMax posts through an **incoming webhook**, not a bot. Connecting runs Discord's
OAuth2 flow with the `webhook.incoming` scope: on Discord's own consent screen the user
picks a server and a channel, and Discord returns a webhook for that channel in the
token response. One connected account = one channel; "Add channel" connects another.
Docs: https://docs.discord.com/developers/topics/oauth2 (section "Webhooks").

The `guilds` scope is also requested, only to read the server's name and icon once at
connect time (the webhook carries ids only). Discord has no user-token scope that
reveals a channel's name, so accounts are named `Server name · channel …1234` (the last
digits of the channel id).

## 1. Create the Discord application

1. Open https://discord.com/developers/applications and click **New Application**.
   Use our own app name and icon; they appear on the consent screen and as the
   default name/avatar of the webhooks it creates.
2. **General Information**: set the Privacy Policy URL (`https://viewsmax.com/privacy/`)
   and Terms of Service URL; Discord shows them on the consent screen.
3. **OAuth2**:
   - Copy the **Client ID**, and **Reset Secret** to get the **Client Secret**.
   - **Redirects** — add all that apply:
     - `https://viewsmax.com/oauth/callback` (website connect flow; the frontend sends `{origin}/oauth/callback`)
     - `https://api.viewsmax.com/connect/discord/callback` (sign-up flow from Claude/ChatGPT)
     - `http://localhost:8080/oauth/callback` (local testing, optional)
4. No bot user is needed: leave the **Bot** page alone and don't add the `bot` scope.

```
DISCORD_CLIENT_ID=
DISCORD_CLIENT_SECRET=
DISCORD_ENABLED=true
```

Scopes requested on connect: `webhook.incoming guilds` (`config/social.php`).
Discord's docs list no review step for these two scopes (privileged intents and bot
verification concern bots only); check the portal for any notice before launch.

## 2. Test it

1. Connect Discord on the Connections page; on Discord's screen pick a server you
   manage and a **text or announcement** channel (forum and media channels need a
   thread per post and are refused with a clear message).
2. Post text, then an image, then a video from the composer.
3. In Discord, delete the webhook (channel settings → Integrations → Webhooks) and
   post again: the target fails with "webhook no longer exists" and the account
   shows Reconnect.
4. Disconnect the account in ViewsMax: the webhook disappears from the channel's
   Integrations list.

Only the live API can confirm: the consent screen, the token response shape, file
limits on boosted servers and Discord's real error bodies.

## Behaviour (what the code guarantees)

- **The token is a secret.** The webhook token equals posting rights in the channel.
  It is stored only in the encrypted `access_token` column; the webhook URL (which
  embeds it) is never stored; URLs are redacted from error text, and
  `App\Support\SentryScrubber` strips it from Sentry's HTTP breadcrumbs and spans.
  The short-lived OAuth access token is used once and not kept.
- **No mass pings.** Messages are sent with `allowed_mentions: {parse: ["users"]}`:
  `@everyone`, `@here` and role mentions in a caption stay plain text; only a
  deliberate user mention (`<@id>`) notifies.
- **Limits** (Discord docs, Nov 2026): text 2000 characters; up to 10 files per
  message; 20 MiB per file (higher on boosted servers, not assumed); 25 MiB per
  request. A file over a limit fails the target with the limit named; nothing is
  dropped silently.
- **The channel is always chosen.** With several Discord channels connected, a post
  that doesn't name the account is refused (`POST/PUT /api/posts`, and the job if a
  channel was added after scheduling).
- **Deleted webhook / channel** (404, codes 10015/10003/50027): the account is
  flagged for reconnect and later posts fail without calling Discord, as Discord asks.
- **Rate limits** (429): the job comes back after Discord's `retry_after`.
- **Disconnect** (Connections page, the AI's `disconnect_account`, `user:erase`)
  deletes the webhook in Discord, best effort; the account row goes either way.
- **Reconnecting a channel** replaces its webhook and deletes the old one.
