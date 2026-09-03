# viewsmax/seo-engine

Automated SEO growth engine, packaged as a standalone Composer library so it
stays out of the main application (and out of any open-sourced tree).

**Pipeline** (runs daily at 06:30 via the scheduler when enabled):

1. **Discover** — mines the keywords each competitor domain ranks for
   (DataForSEO Labs), keeps those that clear volume/difficulty thresholds, and
   scores buying intent (commercial modifiers + CPC signal). Informational-only
   keywords are dropped.
2. **Draft** — Anthropic writes a full article (title / slug / meta / HTML) for
   the highest-intent keywords, linking to your site once.
3. **Publish** — pushes queued articles to WordPress over REST, capped at
   `articles_per_week`.
4. **Prospect** — stores the pages that link to your competitors as backlink
   outreach targets (a mini-CRM: new → contacted → won/rejected).

## Install (path repo — already wired in this app)

```json
"repositories": [{"type": "path", "url": "packages/viewsmax/seo-engine"}],
"require": {"viewsmax/seo-engine": "@dev"}
```

## Configuration (.env)

```env
SEO_ENGINE_ENABLED=true                 # master kill-switch (default false)
SEO_COMPETITORS=hootsuite.com,buffer.com
SEO_SITE_URL=https://viewsmax.com
SEO_ARTICLES_PER_WEEK=3
SEO_AUTO_PUBLISH=true                   # false = drafts wait in review

DATAFORSEO_LOGIN=...                    # app.dataforseo.com credentials
DATAFORSEO_PASSWORD=...

SEO_WP_URL=https://blog.viewsmax.com    # WordPress REST target
SEO_WP_USERNAME=bot
SEO_WP_APP_PASSWORD=xxxx xxxx xxxx      # wp-admin -> Profile -> Application Passwords

# Drafting reuses services.anthropic (ANTHROPIC_API_KEY / ANTHROPIC_MODEL).
```

## Commands

```bash
php artisan seo:run                  # full pipeline (what the scheduler runs)
php artisan seo:discover-keywords    # stage 1 only
php artisan seo:draft-articles --limit=3
php artisan seo:publish-articles
php artisan seo:prospect-backlinks
```

## Data

- `seo_keywords` — mined keywords + metrics + status (discovered/drafted/published/skipped)
- `seo_articles` — drafts and their WordPress post id/url (review/queued/published/failed)
- `seo_backlink_prospects` — outreach targets (new/contacted/won/rejected + notes)

## Swapping a vendor

Every stage is a contract bound in `SeoEngineServiceProvider` — rebind in the
host app to replace a vendor without touching the pipeline:

| Contract | Default |
|---|---|
| `KeywordSource` | DataForSEO Labs ranked-keywords |
| `BacklinkSource` | DataForSEO Backlinks API |
| `ArticleWriter` | Anthropic Messages API |
| `ArticlePublisher` | WordPress REST |

## Safety

- `SEO_ENGINE_ENABLED=false` freezes everything: commands no-op, nothing schedules.
- `SEO_AUTO_PUBLISH=false` keeps every draft in `review` until you flip its
  status to `queued`.
- The weekly cap counts *actual* published articles in the trailing 7 days, so
  restarts/retries can't burst-publish.
