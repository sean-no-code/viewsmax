<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Thin client for the Kit (ConvertKit) v3 API (https://developers.kit.com).
 * Subscribing through a tag endpoint both creates/updates the subscriber and
 * applies the tag in one call.
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
     * Subscribe an email (with optional first name) and apply a tag. Defaults to the
     * configured converted tag; pass $tag to apply a different one (e.g. abandoned cart).
     */
    public function subscribe(string $email, ?string $firstName = null, ?string $tag = null): bool
    {
        if (empty($this->apiKey)) {
            Log::warning('Kit (ConvertKit) API key not configured.');

            return false;
        }

        $tagName = $tag ?? $this->tagName;

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
        } catch (\Exception $e) {
            Log::error("Exception subscribing {$email} to Kit: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Remove a tag from the subscriber identified by email. Requires the API secret.
     * A subscriber/tag that doesn't exist is treated as a successful no-op so this is
     * safe to call unconditionally on conversion. Returns false only on a real error.
     */
    public function removeTag(string $email, string $tag): bool
    {
        if (empty($this->apiSecret)) {
            Log::warning('Kit (ConvertKit) API secret not configured; cannot remove tag.');

            return false;
        }

        try {
            $tagId = $this->resolveTagId($tag);
            if (! $tagId) {
                // No such tag → nothing to remove.
                return true;
            }

            // Kit v3: POST /tags/{id}/unsubscribe removes the tag from the subscriber
            // matching the email (auth via api_secret).
            $response = Http::acceptJson()->timeout(15)
                ->post(self::BASE."/tags/{$tagId}/unsubscribe", [
                    'api_secret' => $this->apiSecret,
                    'email' => $email,
                ]);

            if ($response->successful() || $response->status() === 404) {
                Log::info("Removed Kit tag \"{$tag}\" from {$email}.");

                return true;
            }

            Log::error("Failed to remove Kit tag \"{$tag}\" from {$email}: ".$response->body());

            return false;
        } catch (\Exception $e) {
            Log::error("Exception removing Kit tag \"{$tag}\" from {$email}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Find a tag's id by name (creating the tag if it doesn't exist yet).
     * Cached per tag for a day; failures return null and are retried on the next call.
     */
    protected function resolveTagId(string $tagName): ?int
    {
        return Cache::remember(
            'kit.tag_id.'.Str::slug($tagName),
            now()->addDay(),
            function () use ($tagName): ?int {
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
            }
        );
    }
}
