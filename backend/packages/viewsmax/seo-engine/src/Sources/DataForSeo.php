<?php

namespace ViewsMax\SeoEngine\Sources;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use ViewsMax\SeoEngine\Contracts\BacklinkSource;
use ViewsMax\SeoEngine\Contracts\KeywordSource;

/**
 * DataForSEO adapter — one class for both keyword mining (Labs: ranked
 * keywords a competitor's domain ranks for) and backlink prospecting
 * (Backlinks API: pages linking to a competitor). Basic-auth, POST-array
 * request shape per their v3 API.
 */
class DataForSeo implements BacklinkSource, KeywordSource
{
    public function rankedKeywords(string $competitorDomain, int $limit = 100): Collection
    {
        $rows = $this->post('/dataforseo_labs/google/ranked_keywords/live', [[
            'target' => $competitorDomain,
            'location_code' => config('seo-engine.dataforseo.location_code'),
            'language_code' => config('seo-engine.dataforseo.language_code'),
            'limit' => $limit,
            // Organic only; skip their branded terms cluttering the list.
            'item_types' => ['organic'],
        ]]);

        return $rows->map(fn (array $item) => [
            'keyword' => (string) data_get($item, 'keyword_data.keyword', ''),
            'search_volume' => (int) data_get($item, 'keyword_data.keyword_info.search_volume', 0),
            'difficulty' => (int) data_get($item, 'keyword_data.keyword_properties.keyword_difficulty', 0),
            'cpc' => (float) data_get($item, 'keyword_data.keyword_info.cpc', 0),
            'competitor_url' => data_get($item, 'ranked_serp_element.serp_item.url'),
        ])->filter(fn ($k) => $k['keyword'] !== '')->values();
    }

    public function linksTo(string $competitorDomain, int $limit = 25): Collection
    {
        $rows = $this->post('/backlinks/backlinks/live', [[
            'target' => $competitorDomain,
            'limit' => $limit,
            'mode' => 'one_per_domain', // one prospect per referring domain
            'filters' => ['dofollow', '=', true],
        ]]);

        return $rows->map(fn (array $item) => [
            'url' => (string) data_get($item, 'url_from', ''),
            'domain' => (string) data_get($item, 'domain_from', ''),
            'competitor_url' => data_get($item, 'url_to'),
            'anchor' => data_get($item, 'anchor'),
            'domain_rank' => (int) data_get($item, 'domain_from_rank', 0),
            'dofollow' => (bool) data_get($item, 'dofollow', true),
        ])->filter(fn ($p) => $p['url'] !== '' && $p['domain'] !== '')->values();
    }

    /** POST a task array and return the first task's result items. */
    private function post(string $endpoint, array $payload): Collection
    {
        $cfg = config('seo-engine.dataforseo');
        if (empty($cfg['login']) || empty($cfg['password'])) {
            throw new RuntimeException('DataForSEO credentials are not configured (DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD).');
        }

        $response = Http::withBasicAuth($cfg['login'], $cfg['password'])
            ->timeout(60)
            ->post($cfg['base_url'].$endpoint, $payload);

        if (! $response->successful()) {
            Log::error('[seo-engine] DataForSEO request failed', ['endpoint' => $endpoint, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);
            throw new RuntimeException("DataForSEO {$endpoint} failed (HTTP {$response->status()}).");
        }

        return collect(data_get($response->json(), 'tasks.0.result.0.items', []) ?? []);
    }
}
