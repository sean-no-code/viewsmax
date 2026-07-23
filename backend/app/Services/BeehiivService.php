<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Thin client for the Beehiiv API v2 (https://developers.beehiiv.com). Auth is a
 * per-publication API key sent as a Bearer token; keys are stored encrypted per
 * user in BeehiivConnection and passed in here — this service never persists them.
 */
class BeehiivService
{
    private const BASE = 'https://api.beehiiv.com/v2';

    /**
     * Validate a key by listing the publications it can access.
     *
     * @return array<int, array{id:string, name:?string}>
     *
     * @throws InvalidArgumentException on an invalid/unauthorised key (surface as 422)
     * @throws RuntimeException on any other transport/API failure (surface as 502)
     */
    public function fetchPublications(string $apiKey): array
    {
        try {
            $response = Http::withToken($apiKey)->acceptJson()->timeout(15)->get(self::BASE.'/publications');
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not reach Beehiiv: '.$e->getMessage());
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new InvalidArgumentException('Beehiiv rejected the API key.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Beehiiv API error (HTTP '.$response->status().').');
        }

        return collect($response->json('data') ?? [])
            ->map(fn ($p) => ['id' => $p['id'] ?? null, 'name' => $p['name'] ?? null])
            ->filter(fn ($p) => ! empty($p['id']))
            ->values()
            ->all();
    }

    /**
     * Recent posts for a publication, for the link-placement picker.
     *
     * @return array<int, array{id:string, title:?string, web_url:?string}>
     */
    public function fetchPosts(string $apiKey, string $publicationId, int $limit = 50): array
    {
        try {
            $response = Http::withToken($apiKey)->acceptJson()->timeout(15)
                ->get(self::BASE."/publications/{$publicationId}/posts", [
                    'limit' => $limit, 'order_by' => 'created', 'direction' => 'desc',
                ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not reach Beehiiv: '.$e->getMessage());
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new InvalidArgumentException('Beehiiv rejected the API key.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Beehiiv API error (HTTP '.$response->status().').');
        }

        return collect($response->json('data') ?? [])
            ->map(fn ($p) => ['id' => $p['id'] ?? null, 'title' => $p['title'] ?? null, 'web_url' => $p['web_url'] ?? null])
            ->filter(fn ($p) => ! empty($p['id']))
            ->values()
            ->all();
    }

    /**
     * "Views" for a single Beehiiv post — the reach denominator for a link placed
     * in that post. A newsletter's reach is who actually saw it: distinct email
     * opens PLUS web-page views. (Beehiiv needs `expand=stats`, NOT the array form
     * `expand[]` — Laravel's array query encoding produces `expand[0]=stats`, which
     * Beehiiv ignores and returns no stats.) Returns null when unavailable.
     */
    public function fetchPostViews(string $apiKey, string $publicationId, string $postId): ?int
    {
        try {
            $response = Http::withToken($apiKey)->acceptJson()->timeout(15)
                ->get(self::BASE."/publications/{$publicationId}/posts/{$postId}", ['expand' => 'stats']);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $stats = $response->json('data.stats');
        if (! is_array($stats)) {
            return null;
        }

        $webViews = (int) ($stats['web']['views'] ?? 0);
        $emailOpens = (int) ($stats['email']['unique_opens'] ?? 0);

        return $webViews + $emailOpens;
    }
}
