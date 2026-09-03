<?php

namespace ViewsMax\SeoEngine\Services;

use Illuminate\Support\Facades\Log;
use ViewsMax\SeoEngine\Contracts\KeywordSource;
use ViewsMax\SeoEngine\Models\SeoKeyword;
use ViewsMax\SeoEngine\Models\SeoProfile;

/**
 * Mines a profile's competitors for the keywords they rank on, keeps the ones
 * that clear volume/difficulty thresholds, and scores buying intent.
 * High-intent = commercial modifiers ("best", "vs", "alternative", "pricing")
 * plus a paid-click signal (CPC > 0 means advertisers value the term).
 */
class KeywordDiscovery
{
    public function __construct(private KeywordSource $source) {}

    /** @return int newly stored keywords for this profile */
    public function run(SeoProfile $profile, int $perCompetitor = 100): int
    {
        $cfg = config('seo-engine.keywords');
        $stored = 0;

        foreach ($profile->competitors as $domain) {
            try {
                $keywords = $this->source->rankedKeywords($domain, $perCompetitor);
            } catch (\Throwable $e) {
                Log::error('[seo-engine] keyword discovery failed', ['profile_id' => $profile->id, 'competitor' => $domain, 'error' => $e->getMessage()]);

                continue;
            }

            foreach ($keywords as $k) {
                if ($k['search_volume'] < $cfg['min_search_volume']) {
                    continue;
                }
                if ($k['difficulty'] > $cfg['max_difficulty']) {
                    continue;
                }

                $intent = $this->intentScore($k['keyword'], (float) $k['cpc']);
                if ($intent === 0) {
                    continue; // informational-only — not worth an automated article
                }

                SeoKeyword::firstOrCreate(
                    ['seo_profile_id' => $profile->id, 'keyword' => mb_strtolower($k['keyword'])],
                    [
                        'competitor_domain' => $domain,
                        'competitor_url' => $k['competitor_url'],
                        'search_volume' => $k['search_volume'],
                        'difficulty' => $k['difficulty'],
                        'cpc' => $k['cpc'],
                        'intent_score' => $intent,
                        'status' => SeoKeyword::STATUS_DISCOVERED,
                    ]
                )->wasRecentlyCreated && $stored++;
            }
        }

        return $stored;
    }

    /** 0 = no commercial signal; higher = stronger buying intent. */
    public function intentScore(string $keyword, float $cpc): int
    {
        $kw = mb_strtolower($keyword);
        $score = 0;
        foreach (config('seo-engine.keywords.intent_terms', []) as $term) {
            if (str_contains($kw, $term)) {
                $score += 10;
            }
        }
        if ($cpc >= 0.5) {
            $score += 10;
        }
        if ($cpc >= 2) {
            $score += 10;
        }

        return $score;
    }
}
