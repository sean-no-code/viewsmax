---
name: viewsmax
description: >
  Post to social media, schedule content, create tracked offer links, and read
  click/conversion/revenue analytics via ViewsMax (viewsmax.com). Use when the
  user asks to publish or schedule social posts (YouTube, TikTok, X, LinkedIn,
  Threads, Instagram, Bluesky), promote an offer with tracked links, or check
  which content is driving sales.
metadata:
  requires_env: VIEWSMAX_API_KEY
  homepage: https://viewsmax.com/ai
---

# ViewsMax

ViewsMax is a social posting + sales tracking SaaS. This skill drives its REST
API on the user's behalf.

## Setup

1. The user creates an API key at **viewsmax.com → Settings → AI Assistant
   Access** (choose *full access* for posting; *read-only* for analytics only).
2. Export it as `VIEWSMAX_API_KEY` in the agent's environment.

- Base URL: `https://api.viewsmax.com/api`
- Every request: `Authorization: Bearer $VIEWSMAX_API_KEY` and `Accept: application/json`
- Responses use a `{ success, message, data }` envelope.
- Capability self-check (no auth): `GET https://api.viewsmax.com/api/ai`
- Full API reference: https://api.viewsmax.com/docs (OpenAPI: /docs.openapi)

## Core flows

### 1. Check connected accounts (do this before posting)

```bash
curl -s -H "Authorization: Bearer $VIEWSMAX_API_KEY" -H "Accept: application/json" \
  https://api.viewsmax.com/api/social/accounts
```

Only post to platforms that appear here. If a platform is missing, tell the
user to connect it at viewsmax.com → Connections.

### 2. Upload media (required for TikTok / Instagram / YouTube)

```bash
curl -s -X POST -H "Authorization: Bearer $VIEWSMAX_API_KEY" -H "Accept: application/json" \
  -F "file=@video.mp4" \
  https://api.viewsmax.com/api/posts/media
```

Returns a media entry (`type`, `url`, `path`) to pass in the post's `media` array.

### 3. Create, schedule, or draft a post

```bash
curl -s -X POST -H "Authorization: Bearer $VIEWSMAX_API_KEY" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{
    "caption": "Launch day! Grab it here: https://vmx.link/abc",
    "platforms": ["x", "linkedin"],
    "status": "scheduled",
    "scheduled_at": "2026-07-14 18:00:00",
    "media": []
  }' \
  https://api.viewsmax.com/api/posts
```

- `status`: `draft` | `posted` (publish now) | `scheduled` (+ `scheduled_at`).
- `options`: optional per-platform publish settings, keyed by platform — e.g.
  `{"tiktok": {"privacy_level": "SELF_ONLY", "auto_add_music": true}}`.
  `options.tiktok.auto_add_music` (boolean, **default `false`**) lets TikTok
  auto-add its recommended background music to a **photo slideshow**; it is
  ignored for video, and there is no music option for Instagram.
- Publishing is **asynchronous**. Poll `GET /api/posts/{id}` and check each
  entry in `targets[]` — a post is only live on a platform when its target
  status is `published`. Never report success before that.

### 4. Create an offer and a tracked link

```bash
# Offer = the product/campaign being promoted
curl -s -X POST -H "Authorization: Bearer $VIEWSMAX_API_KEY" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{ "name": "Course launch", "offer_url": "https://example.com/course", "conversion_value": 199 }' \
  https://api.viewsmax.com/api/tracking-events

# Tracked link inside that offer (use the returned offer id)
curl -s -X POST -H "Authorization: Bearer $VIEWSMAX_API_KEY" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{ "tracking_event_id": 123, "name": "X launch thread", "placement": "x" }' \
  https://api.viewsmax.com/api/tracking-links
```

Put the returned tracked URL in captions/bios so clicks and conversions are
attributed to the content that drove them.

### 5. Read analytics

```bash
curl -s -H "Authorization: Bearer $VIEWSMAX_API_KEY" -H "Accept: application/json" \
  "https://api.viewsmax.com/api/tracking-events/stats"

curl -s -H "Authorization: Bearer $VIEWSMAX_API_KEY" -H "Accept: application/json" \
  "https://api.viewsmax.com/api/tracking-events/timeseries?bucket=day"
```

## Failure modes

- `401` — missing/invalid key. Ask the user to re-copy or rotate it.
- `403` — read-only key attempting a write, an endpoint outside the API-key
  surface, or a plan limit. Relay the `message` to the user.
- `422` — validation error (e.g. caption over a platform's limit). The
  `message` says which platform/field; fix and retry.
- `429` — rate limited (180 posts/hour, 40 media uploads/hour). Back off.
- API keys deliberately cannot touch billing, account, or key management.
