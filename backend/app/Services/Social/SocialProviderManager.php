<?php

namespace App\Services\Social;

use App\Services\Social\Contracts\SocialProviderInterface;
use App\Services\Social\Providers\BlueskyProvider;
use App\Services\Social\Providers\FacebookProvider;
use App\Services\Social\Providers\GoogleBusinessProvider;
use App\Services\Social\Providers\InstagramProvider;
use App\Services\Social\Providers\LinkedInProvider;
use App\Services\Social\Providers\ThreadsProvider;
use App\Services\Social\Providers\TikTokProvider;
use App\Services\Social\Providers\XProvider;
use App\Services\Social\Providers\YouTubeProvider;
use InvalidArgumentException;

class SocialProviderManager
{
    /**
     * Map of platform key → provider class.
     */
    protected array $providers = [
        'facebook' => FacebookProvider::class,
        'instagram' => InstagramProvider::class,
        'threads' => ThreadsProvider::class,
        'linkedin' => LinkedInProvider::class,
        'bluesky' => BlueskyProvider::class,
        'x' => XProvider::class,
        'tiktok' => TikTokProvider::class,
        'youtube' => YouTubeProvider::class,
        'google_business' => GoogleBusinessProvider::class,
    ];

    /**
     * @var array<string, SocialProviderInterface>
     */
    protected array $resolved = [];

    /**
     * Resolve a provider for a platform key.
     */
    public function for(string $platform): SocialProviderInterface
    {
        $platform = strtolower($platform);

        if (! isset($this->providers[$platform])) {
            throw new InvalidArgumentException("Unsupported social platform: {$platform}");
        }

        return $this->resolved[$platform] ??= app($this->providers[$platform]);
    }

    /**
     * All supported platform keys.
     *
     * @return array<int, string>
     */
    public function platforms(): array
    {
        return array_keys($this->providers);
    }

    public function supports(string $platform): bool
    {
        return isset($this->providers[strtolower($platform)]);
    }

    /**
     * Whether the platform is enabled and has the credentials it needs to run.
     */
    public function isConfigured(string $platform): bool
    {
        $platform = strtolower($platform);
        $config = config("social.platforms.{$platform}");

        if (! $config || ($config['enabled'] ?? true) === false) {
            return false;
        }

        // Bluesky needs no app credentials (handle + app password at connect time).
        if ($platform === 'bluesky') {
            return true;
        }

        return ! empty($config['client_id']) && ! empty($config['client_secret']);
    }

    /**
     * Metadata describing every platform, for the frontend connect screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalog(): array
    {
        return collect($this->platforms())->map(function (string $platform) {
            $config = config("social.platforms.{$platform}", []);

            return [
                'platform' => $platform,
                'label' => $config['label'] ?? ucfirst($platform),
                'enabled' => (bool) ($config['enabled'] ?? true),
                'configured' => $this->isConfigured($platform),
                'uses_oauth' => $this->for($platform)->usesOAuth(),
            ];
        })->all();
    }
}
