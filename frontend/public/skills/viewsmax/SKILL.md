---
name: viewsmax
description: >
  Post to social media, schedule content, create tracked offer links, read
  click/conversion/revenue analytics, and research outlier videos via ViewsMax
  (viewsmax.com). Use when the user asks to publish or schedule social posts
  (YouTube, TikTok, X, LinkedIn, Threads, Instagram, Bluesky), promote an offer
  with tracked links, check which content is driving sales, or find and break
  down videos that massively over-performed (content ideation).
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

### 6. Research outlier videos

Outliers are videos that massively over-performed their channel's average
(`outlier_score` = views ÷ channel average views) on YouTube, TikTok and
Instagram. Use them for ideation: what hooks, formats and topics are working.

```bash
# Browse the curated feed (filters: platform, min_score, min_views, max_views,
# min_subs, max_subs, published_after/before, duration_type=long|shorts,
# countries[]=US, channels[]=<channel id>, sort_by=score|views|date|recent,
# page, per_page ≤ 100)
curl -s -H "Authorization: Bearer $VIEWSMAX_API_KEY" -H "Accept: application/json" \
  "https://api.viewsmax.com/api/outliers?platform=youtube&min_score=20&duration_type=shorts&per_page=20"

# Keyword browse: returns title matches already in the database plus a
# `status`. If status is queued/in_progress, start a scrape and poll again:
curl -s -X POST -H "Authorization: Bearer $VIEWSMAX_API_KEY" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{ "term": "faceless youtube automation" }' \
  https://api.viewsmax.com/api/outliers/search

# Pull a specific video in by URL (202 + queued:true when it has to be
# ingested — poll GET /api/outliers/{platform}/{video_id} until it appears)
curl -s -X POST -H "Authorization: Bearer $VIEWSMAX_API_KEY" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{ "platform": "youtube", "url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ" }' \
  https://api.viewsmax.com/api/outliers/fetch

# AI breakdown (hook, structure, why it worked). POST queues generation;
# GET returns status none|pending|processing|completed|failed + payload.
curl -s -X POST -H "Authorization: Bearer $VIEWSMAX_API_KEY" -H "Accept: application/json" \
  https://api.viewsmax.com/api/outliers/youtube/dQw4w9WgXcQ/breakdown
curl -s -H "Authorization: Bearer $VIEWSMAX_API_KEY" -H "Accept: application/json" \
  https://api.viewsmax.com/api/outliers/youtube/dQw4w9WgXcQ/breakdown

# Library: save with tags, list, remove
curl -s -X POST -H "Authorization: Bearer $VIEWSMAX_API_KEY" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{ "platform": "youtube", "video_id": "dQw4w9WgXcQ", "tags": ["hooks"], "snapshot": { "title": "…" } }' \
  https://api.viewsmax.com/api/outliers/library
curl -s -H "Authorization: Bearer $VIEWSMAX_API_KEY" -H "Accept: application/json" \
  "https://api.viewsmax.com/api/outliers/library?tags[]=hooks"
```

Poll breakdowns every 10–15 s; generation takes up to a couple of minutes.

## Failure modes

- `401` — missing/invalid key. Ask the user to re-copy or rotate it.
- `403` — read-only key attempting a write, an endpoint outside the API-key
  surface, or a plan limit. Relay the `message` to the user.
- `422` — validation error (e.g. caption over a platform's limit). The
  `message` says which platform/field; fix and retry.
- `429` — rate limited (180 posts/hour, 40 media uploads/hour, 30 outlier
  searches/hour, 60 outlier fetches/hour, 30 breakdowns/hour). Back off.
- API keys deliberately cannot touch billing, account, or key management.
