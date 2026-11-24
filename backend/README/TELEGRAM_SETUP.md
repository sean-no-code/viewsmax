# Telegram — bot setup and testing

Telegram posting is off until `TELEGRAM_BOT_TOKEN` and `TELEGRAM_BOT_USERNAME` are
set: without them Telegram shows "Coming soon" on Connections and is left out of the
composer and the AI tools. `TELEGRAM_ENABLED=false` hides it even when both are set.

There is no OAuth app and no review: ViewsMax runs **one bot** that users add to their
own channels and groups. Telegram has no approval step for bots.

## 1. Create the bot (BotFather)

1. In Telegram, open [@BotFather](https://t.me/BotFather) and send `/newbot`.
2. Pick a display name (e.g. "ViewsMax") and a username ending in `bot`
   (e.g. `ViewsMaxBot`). BotFather replies with the **token**
   (`123456789:AA...`). Treat it like a password: anyone with it controls the bot.
3. Optional, recommended: `/setdescription` and `/setuserpic` so users recognise the
   bot when they add it, and `/setjoingroups` left **enabled** (users must be able to
   add it to groups).
4. Leave **privacy mode** on (the default). ViewsMax asks users to make the bot an
   administrator, and bot admins receive every message in the group anyway
   (https://core.telegram.org/bots/features#privacy-mode). Commands written as
   `/connect@ViewsMaxBot CODE` also reach a non-admin bot, but the connect still
   requires admin.

Use a **separate bot per environment** (production, staging, each developer). A bot
has exactly one webhook, so two environments sharing a token steal each other's
updates.

## 2. Configure ViewsMax

```
TELEGRAM_BOT_TOKEN=123456789:AA...
TELEGRAM_BOT_USERNAME=ViewsMaxBot      # without the @
TELEGRAM_ENABLED=true                  # optional, default true
```

Then register the webhook (once per environment, and again if the API host changes):

```
php artisan telegram:set-webhook
```

It calls `setWebhook` with `{APP_URL}/api/telegram/webhook`, a secret derived from the
token (sent back by Telegram in `X-Telegram-Bot-Api-Secret-Token` and checked on every
call), and `allowed_updates = ["message", "channel_post"]`. Telegram only delivers to
public HTTPS URLs on ports 443, 80, 88 or 8443; locally, pass a tunnel URL:

```
php artisan telegram:set-webhook --url=https://<your-tunnel>.ngrok.app/api/telegram/webhook
```

Changing the token changes the secret: run the command again after rotating it.

## 3. How a user connects a chat

1. Connections → Telegram → Connect. The dialog shows a one-time code (8 characters,
   valid 15 minutes, bound to that ViewsMax user, kept in the cache).
2. The user adds the bot to their channel or group as an **administrator** (in a
   channel with the **Post messages** right) and posts `/connect CODE` there.
3. The webhook stores which chat the code appeared in. The dialog polls
   `POST /api/social/telegram/connect {code}` (202 `pending` until then) and the backend
   checks, before linking:
   - the chat is a channel, group or supergroup (not a private chat);
   - the bot is an administrator there (`getChatMember`), with `can_post_messages` in a
     channel;
   - the code was posted by an admin: in a channel only admins can post; in a group the
     sender must be `creator`/`administrator` (`getChatMember`) or an anonymous admin
     (message sent on behalf of the group itself). Messages sent as another channel or
     auto-forwarded from a linked channel are refused.
4. The account is created (`platform_account_id` = chat id, name = chat title, username
   when the chat is public), the code is spent and the `/connect` message is deleted
   (best effort).

Disconnecting removes the account in ViewsMax only (Telegram has no revoke call); the bot
stays in the chat until the user removes it there.

A typed chat id or `@username` is never accepted: the bot is an admin in every connected
chat, so that would let any user post into any of them.

The agent sign-up page (`/register/setup`) shows the same steps without JavaScript and a
**Check** button.

## 4. Publishing

| Post | Bot API call |
|---|---|
| Text only (≤ 4096 characters) | `sendMessage` |
| 1 photo / 1 video | `sendPhoto` / `sendVideo`, text as caption |
| 2–10 photos/videos (mixed) | `sendMediaGroup`, caption on the first item |
| Text over 1024 characters with media | media without caption, then `sendMessage` with the text |

- Media is downloaded (through `SafeHttp`) and **uploaded**, never passed as a URL:
  uploads allow photos up to 10 MB and other files up to 50 MB, URLs only 5 MB / 20 MB
  (https://core.telegram.org/bots/api#sending-files). Larger files fail with a clear
  message; nothing is posted.
- Text is sent as plain text (no `parse_mode`), so user text can't break on Markdown/HTML.
- Lengths are counted in UTF-16 code units (an emoji outside the BMP counts 2).
- Post links: `https://t.me/<username>/<id>` for public chats,
  `https://t.me/c/<id>/<id>` for private supergroups/channels; basic groups have none.
- Errors: 429 → the job waits `retry_after` seconds and retries; 5xx / network → queue
  retry; 403 or "chat not found" / "not enough rights" → the account is flagged
  needs-reconnect with a message to re-add the bot as admin; 401 (bad token) → logged at
  error level, the user's account is left alone; anything else → the target fails with
  Telegram's reason. A basic group that became a supergroup (`migrate_to_chat_id`) is
  followed automatically.
- The bot token is in every Bot API URL. It is never logged, never stored in target
  errors and never returned; Sentry breadcrumbs/spans are scrubbed
  (`App\Support\SentryScrubber`).

## 5. What can only be checked with a real bot

The tests fake every Bot API call. Before launch, with a real bot on a public HTTPS host:

- `php artisan telegram:set-webhook` succeeds and `getWebhookInfo` shows no errors;
- connecting a public channel, a private channel, a group and a supergroup;
- a non-admin group member's code is refused;
- text, one photo, one video, an album and a >1024-character caption all post;
- removing the bot from a chat, then publishing, flags the account for reconnect.
