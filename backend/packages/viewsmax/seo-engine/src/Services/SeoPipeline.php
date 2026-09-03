<?php

namespace ViewsMax\SeoEngine\Services;

use Illuminate\Support\Facades\Log;
use ViewsMax\SeoEngine\Contracts\ArticlePublisher;
use ViewsMax\SeoEngine\Contracts\ArticleWriter;
use ViewsMax\SeoEngine\Contracts\BacklinkSource;
use ViewsMax\SeoEngine\Contracts\ImageWriter;
use ViewsMax\SeoEngine\Models\SeoArticle;
use ViewsMax\SeoEngine\Models\SeoBacklinkProspect;
use ViewsMax\SeoEngine\Models\SeoKeyword;
use ViewsMax\SeoEngine\Models\SeoProfile;

/**
 * The per-profile draft -> publish -> prospect stages. Discovery lives in
 * KeywordDiscovery; this class owns everything downstream of a stored keyword.
 */
class SeoPipeline
{
    public function __construct(
        private ArticleWriter $writer,
        private ArticlePublisher $publisher,
        private BacklinkSource $backlinks,
        private ImageWriter $images,
    ) {}

    /**
     * Draft articles for the profile's best undrafted keywords (highest
     * intent, then volume). Auto-publish profiles queue them; otherwise they
     * wait in review.
     *
     * @return int articles drafted
     */
    public function draft(SeoProfile $profile, int $limit): int
    {
        $keywords = $profile->keywords()
            ->where('status', SeoKeyword::STATUS_DISCOVERED)
            ->orderByDesc('intent_score')
            ->orderByDesc('search_volume')
            ->limit($limit)
            ->get();

        $drafted = 0;
        foreach ($keywords as $keyword) {
            try {
                $draft = $this->writer->draft($keyword);
            } catch (\Throwable $e) {
                Log::error('[seo-engine] draft failed', ['profile_id' => $profile->id, 'keyword' => $keyword->keyword, 'error' => $e->getMessage()]);

                continue;
            }

            $article = $keyword->articles()->create($draft + [
                'seo_profile_id' => $profile->id,
                'status' => $profile->auto_publish ? SeoArticle::STATUS_QUEUED : SeoArticle::STATUS_REVIEW,
            ]);
            $keyword->update(['status' => SeoKeyword::STATUS_DRAFTED]);
            $this->illustrate($article);
            $drafted++;
        }

        return $drafted;
    }

    /**
     * Publish the profile's queued articles, honouring its weekly cadence cap.
     *
     * @return int articles published
     */
    public function publish(SeoProfile $profile): int
    {
        $cap = max(0, (int) $profile->articles_per_week);
        $publishedThisWeek = $profile->articles()
            ->where('status', SeoArticle::STATUS_PUBLISHED)
            ->where('published_at', '>=', now()->subDays(7))
            ->count();
        $budget = max(0, $cap - $publishedThisWeek);
        if ($budget === 0) {
            return 0;
        }

        $queue = $profile->articles()
            ->where('status', SeoArticle::STATUS_QUEUED)
            ->oldest()
            ->limit($budget)
            ->get();

        $published = 0;
        foreach ($queue as $article) {
            // Backfill images for drafts made before image support (or after a
            // failed generation) so nothing publishes bare.
            if (! $article->featured_image_url) {
                $this->illustrate($article);
            }
            try {
                $ref = $this->publisher->publish($article);
                $article->update([
                    'status' => SeoArticle::STATUS_PUBLISHED,
                    'wordpress_post_id' => $ref['post_id'],
                    'published_url' => $ref['url'],
                    'published_at' => now(),
                    'error' => null,
                ]);
                $article->keyword?->update(['status' => SeoKeyword::STATUS_PUBLISHED]);
                $published++;
            } catch (\Throwable $e) {
                Log::error('[seo-engine] publish failed', ['article_id' => $article->id, 'error' => $e->getMessage()]);
                $article->update(['status' => SeoArticle::STATUS_FAILED, 'error' => $e->getMessage()]);
            }
        }

        return $published;
    }

    /**
     * Attach images to an article — never fatal: an article without pictures
     * still beats no article, so failures only log.
     */
    private function illustrate(SeoArticle $article): void
    {
        if (! config('seo-engine.images.enabled')) {
            return;
        }

        try {
            $this->images->illustrate($article);
        } catch (\Throwable $e) {
            Log::warning('[seo-engine] illustration failed', ['article_id' => $article->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Pull pages linking to the profile's competitors as outreach prospects.
     *
     * @return int new prospects stored
     */
    public function prospect(SeoProfile $profile): int
    {
        $offerHost = parse_url((string) $profile->offer?->offer_url, PHP_URL_HOST);
        $minRank = (int) config('seo-engine.backlinks.min_domain_rank');
        $stored = 0;

        foreach ($profile->competitors as $domain) {
            try {
                $links = $this->backlinks->linksTo($domain, (int) config('seo-engine.backlinks.per_competitor'));
            } catch (\Throwable $e) {
                Log::error('[seo-engine] backlink prospecting failed', ['profile_id' => $profile->id, 'competitor' => $domain, 'error' => $e->getMessage()]);

                continue;
            }

            foreach ($links as $link) {
                if ($link['domain_rank'] < $minRank) {
                    continue;
                }
                if ($offerHost && str_contains($link['domain'], preg_replace('/^www\./', '', $offerHost))) {
                    continue; // already links to the user's own site
                }

                SeoBacklinkProspect::firstOrCreate(
                    ['seo_profile_id' => $profile->id, 'domain' => $link['domain'], 'competitor_domain' => $domain],
                    [
                        'url' => $link['url'],
                        'competitor_url' => $link['competitor_url'],
                        'anchor' => $link['anchor'],
                        'domain_rank' => $link['domain_rank'],
                        'dofollow' => $link['dofollow'],
                        'status' => SeoBacklinkProspect::STATUS_NEW,
                    ]
                )->wasRecentlyCreated && $stored++;
            }
        }

        return $stored;
    }
}
