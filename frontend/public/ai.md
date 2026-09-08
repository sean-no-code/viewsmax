# Connect your AI to ViewsMax

ViewsMax exposes an MCP server and a REST API so AI assistants and agents can
post to a user's connected social accounts (YouTube, TikTok, X, LinkedIn,
Threads, Instagram, Bluesky), manage offers and tracked links, read
click/conversion/revenue analytics, and research outlier videos (content that
massively over-performed its channel, with AI breakdowns of why) — on the
user's behalf, with their permission. User data is private; all access is
authenticated.

- **MCP endpoint:** `https://api.viewsmax.com/api/mcp` (Streamable HTTP)
- **Capability discovery (JSON):** `https://api.viewsmax.com/api/ai`
- **REST API:** `https://api.viewsmax.com/api` — [OpenAPI spec](https://api.viewsmax.com/docs.openapi) · [API reference](https://api.viewsmax.com/docs)

## Credentials

Two options, both sent as a Bearer token:

1. **OAuth (recommended for chat apps).** Point an MCP client at the endpoint
   above; the user signs in and approves in the browser (OAuth 2.1 + PKCE,
   dynamic client registration supported). The consent screen offers
   read-only or full access. No key handling.
2. **API key (for headless agents and scripts).** In the ViewsMax app:
   **Settings → AI Assistant Access → generate key** (`vmx_...`). Choose
   read-only or full access. The key is shown once; rotating it invalidates
   the old one. The same key works on the REST API (posts, offers, tracking,
   stats, outliers — read-only keys are limited to GET).

## Claude (claude.ai / Claude Desktop)

Settings → Connectors → **Add custom connector** → URL
`https://api.viewsmax.com/api/mcp` → complete the sign-in approval.

## Claude Code

```bash
claude mcp add --transport http viewsmax https://api.viewsmax.com/api/mcp
```

or in `.mcp.json`:

```json
{ "mcpServers": { "viewsmax": { "type": "http", "url": "https://api.viewsmax.com/api/mcp" } } }
```

OAuth prompts on first use. For headless use add
`"headers": { "Authorization": "Bearer vmx_YOUR_KEY" }`.

## ChatGPT

Settings → Apps & Connectors → enable **Developer mode** → add a connector
with the MCP URL above and complete OAuth. ViewsMax is an *action* connector
(create/schedule posts, read stats) — use it from regular chats with
connectors enabled; it is not a deep-research search/fetch source.

## Cursor

`.cursor/mcp.json`:

```json
{ "mcpServers": { "viewsmax": {
  "url": "https://api.viewsmax.com/api/mcp",
  "headers": { "Authorization": "Bearer vmx_YOUR_KEY" } } } }
```

## OpenClaw

Install the ViewsMax skill (SKILL.md drives the REST API):
download it from <https://viewsmax.com/skills/viewsmax/SKILL.md> into your
agent's skills directory, then set `VIEWSMAX_API_KEY` in the agent's
environment (full-access key). Alternatively point OpenClaw's MCP support at
the MCP endpoint above.

## Hermes Agent

Add to your MCP servers config:

```json
{ "viewsmax": { "transport": "http",
  "url": "https://api.viewsmax.com/api/mcp",
  "headers": { "Authorization": "Bearer vmx_YOUR_KEY" } } }
```

## Plain REST / curl

```bash
curl -H "Authorization: Bearer vmx_YOUR_KEY" https://api.viewsmax.com/api/posts
```

Responses use a `{ success, message, data }` envelope. Full reference:
<https://api.viewsmax.com/docs>.

## What agents can do (28 MCP tools)

`list_connected_accounts`, `list_brands`, `upload_media`, `create_post`,
`list_posts`, `get_post`, `update_post`, `delete_post`, `list_offers`,
`create_offer`, `get_offer`, `update_offer`, `delete_offer`,
`create_tracking_link`, `get_offer_stats`, `get_stats_timeseries`,
`disconnect_account`, `get_connect_url`, `create_feature_request`,
`list_outliers`, `search_outliers`, `get_outlier`, `fetch_outlier`,
`get_outlier_breakdown`, `generate_outlier_breakdown`, `list_saved_outliers`,
`save_outlier`, `remove_saved_outlier`.

Typical posting flow: `list_connected_accounts` → `upload_media` (TikTok /
Instagram / YouTube need a video or image) → `create_post` (status `draft`,
`posted`, or `scheduled` + `scheduled_at`) → publishing is asynchronous, so
poll `get_post` for per-platform results.

`create_post`/`update_post` accept an `options` object keyed by platform for
per-platform publish settings — e.g. `options.tiktok.privacy_level` and
`options.tiktok.auto_add_music` (boolean, default `false`) which lets TikTok
auto-add its recommended music to a **photo slideshow** (ignored for video;
there is no music option for Instagram).

Typical analytics flow: `list_offers` → `get_offer_stats` /
`get_stats_timeseries` (clicks + conversions bucketed by hour or day).

Typical research flow: `list_outliers` (filter by platform, outlier score,
views, subscribers, date, shorts/long, country) or `search_outliers` to scrape
a new topic, then `fetch_outlier` for a specific URL →
`generate_outlier_breakdown` → poll `get_outlier_breakdown` until `completed`
→ `save_outlier` with tags to keep it in the user's library.

## Security & limits

- Read-only credentials cannot write, anywhere.
- Every AI tool call is recorded in the user's audit log (Settings → AI
  Assistant Access → activity).
- Rate limits: 120 MCP requests/min per token; 180 `create_post`/hour;
  40 `upload_media`/hour; 30 `search_outliers`/hour; 60 `fetch_outlier`/hour;
  30 `generate_outlier_breakdown`/hour. HTTP 429 = back off.
- Rotate the API key any time to revoke access instantly.
