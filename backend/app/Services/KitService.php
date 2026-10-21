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

    protected ?string $apiSecret;

    protected string $tagName;

    public function __construct()
    {
        $this->apiKey = config('services.kit.api_key');
        $this->apiSecret = config('services.kit.api_secret');
        $this->tagName = config('services.kit.tag', 'viewsmax');
    }

    /**
     * Subscribe an email (with optional first name) and apply the configured
     * tag, plus any $extraTags (each applied with its own subscribe call —
     * the v3 tag endpoint applies one tag per call). Never throws: every
     * failure is logged, and the result is false if any tag failed.
     *
     * @param  array<int, string>  $extraTags
     */
    public function subscribe(string $email, ?string $firstName = null, array $extraTags = []): bool
    {
        if (empty($this->apiKey)) {
            Log::info("Kit (ConvertKit) API key not configured; skipping subscribe for {$email}.");

            return false;
        }

        $ok = true;
        foreach (array_unique([$this->tagName, ...$extraTags]) as $tagName) {
            $ok = $this->subscribeWithTag($tagName, $email, $firstName) && $ok;
        }

        return $ok;
    }

    private function subscribeWithTag(string $tagName, string $email, ?string $firstName): bool
    {
        try {
            $tagId = $this->resolveTagId($tagName);
            if (! $tagId) {
                Log::error("Could not resolve Kit tag \"{$tagName}\"; skipping subscribe for {$email}.");

                return false;
            }

            $response = Http::acceptJson()->timeout(15)
                ->post(self::BASE."/tags/{$tagId}/subscribe", array_filter([
                    'api_key' => $this->apiKey,
                    'email' => $email,
                    'first_name' => $firstName,
                ]));

            if ($response->successful()) {
                Log::info("Subscribed {$email} to Kit with tag \"{$tagName}\".");

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
     * Create a tag that isn't in the account yet and return its id.
     *
     * Kit's v3 create-tag endpoint authenticates with the *secret* key, unlike
     * GET /tags and /tags/{id}/subscribe which take the public api_key. Passing
     * api_key here only ever returned an auth error, so a tag missing from the
     * account could never be created and the subscribe was skipped — hence the
     * explicit, actionable log when KIT_API_SECRET isn't set.
     */
    private function createTag(string $tagName): ?int
    {
        if (empty($this->apiSecret)) {
            Log::error("Kit tag \"{$tagName}\" does not exist in the account and KIT_API_SECRET is not set, so it cannot be created. Add the tag in Kit, or set KIT_API_SECRET.");

            return null;
        }

        $created = Http::acceptJson()->timeout(15)
            ->post(self::BASE.'/tags', [
                'api_secret' => $this->apiSecret,
                'tag' => ['name' => $tagName],
            ]);

        if (! $created->successful()) {
            Log::error("Kit tag create for \"{$tagName}\" failed ({$created->status()}): ".$created->body());

            return null;
        }

        // One tag comes back as a single object; Kit answers with an array when
        // asked for several. Accept both so a created tag is never thrown away.
        $id = $created->json('id') ?? $created->json('0.id');

        if (! $id) {
            Log::error("Kit tag create for \"{$tagName}\" returned no id: ".$created->body());

            return null;
        }

        Log::info("Created Kit tag \"{$tagName}\" (id {$id}).");

        return (int) $id;
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

            if (! $response->successful()) {
                Log::error("Kit tag list failed ({$response->status()}): ".$response->body());

                return null;
            }

            foreach ($response->json('tags') ?? [] as $tag) {
                if (strcasecmp($tag['name'] ?? '', $tagName) === 0) {
                    return (int) $tag['id'];
                }
            }

            return $this->createTag($tagName);
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
