<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Thin client for the Kit (ConvertKit) v3 API (https://developers.kit.com).
 * Subscribing through a tag endpoint both creates/updates the subscriber and
 * applies the tag in one call. Called inline from registration for users who
 * ticked the newsletter box; a missing KIT_API_KEY turns it into a logged no-op.
 */
class KitService
{
    private const BASE = 'https://api.convertkit.com/v3';

    protected ?string $apiKey;

    protected string $tagName;

    public function __construct()
    {
        $this->apiKey = config('services.kit.api_key');
        $this->tagName = config('services.kit.tag', 'viewsmax');
    }

    /**
     * Subscribe an email (with optional first name) and apply the configured tag.
     * Never throws: every failure is logged and reported as false.
     */
    public function subscribe(string $email, ?string $firstName = null): bool
    {
        if (empty($this->apiKey)) {
            Log::info("Kit (ConvertKit) API key not configured; skipping subscribe for {$email}.");

            return false;
        }

        try {
            $tagId = $this->resolveTagId($this->tagName);
            if (! $tagId) {
                Log::error("Could not resolve Kit tag \"{$this->tagName}\"; skipping subscribe for {$email}.");

                return false;
            }

            $response = Http::acceptJson()->timeout(15)
                ->post(self::BASE."/tags/{$tagId}/subscribe", array_filter([
                    'api_key' => $this->apiKey,
                    'email' => $email,
                    'first_name' => $firstName,
                ]));

            if ($response->successful()) {
                Log::info("Subscribed {$email} to Kit with tag \"{$this->tagName}\".");

                return true;
            }

            Log::error("Failed to subscribe {$email} to Kit: ".$response->body());

            return false;
        } catch (\Throwable $e) {
            Log::error("Exception subscribing {$email} to Kit: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Find a tag's id by name (creating the tag if it doesn't exist yet).
     * Cached per tag for a day; failures return null and are retried on the next
     * call. The cache is only an optimisation: if the store can't be used (e.g.
     * storage/framework/cache owned by another user) the lookup runs uncached
     * rather than losing the subscribe.
     */
    protected function resolveTagId(string $tagName): ?int
    {
        $lookup = function () use ($tagName): ?int {
            $response = Http::acceptJson()->timeout(15)
                ->get(self::BASE.'/tags', ['api_key' => $this->apiKey]);

            if ($response->successful()) {
                foreach ($response->json('tags') ?? [] as $tag) {
                    if (strcasecmp($tag['name'] ?? '', $tagName) === 0) {
                        return (int) $tag['id'];
                    }
                }
            }

            $created = Http::acceptJson()->timeout(15)
                ->post(self::BASE.'/tags', [
                    'api_key' => $this->apiKey,
                    'tag' => ['name' => $tagName],
                ]);

            if ($created->successful() && $created->json('id')) {
                return (int) $created->json('id');
            }

            return null;
        };

        $key = 'kit.tag_id.'.Str::slug($tagName);

        try {
            if (($cached = Cache::get($key)) !== null) {
                return (int) $cached;
            }
        } catch (\Throwable $e) {
            Log::warning("Kit tag cache read failed, looking up \"{$tagName}\" uncached: ".$e->getMessage());
        }

        $tagId = $lookup();

        if ($tagId !== null) {
            try {
                Cache::put($key, $tagId, now()->addDay());
            } catch (\Throwable $e) {
                Log::warning("Kit tag cache write failed for \"{$tagName}\" (check storage/ ownership): ".$e->getMessage());
            }
        }

        return $tagId;
    }
}
