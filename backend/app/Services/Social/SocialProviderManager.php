<?php

namespace App\Services\Social;

use App\Models\User;
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
     * Whether the platform can supply follower counts and post metrics under the
     * current config. Gated platforms flip on when their scope/flag lands (see
     * config/social: tiktok stats_enabled, instagram reach_enabled); the rest
     * have no stats implementation yet (AbstractSocialProvider defaults).
     */
    public function supportsStats(string $platform): bool
    {
        return match (strtolower($platform)) {
            'youtube', 'x' => true,
            'instagram' => (bool) config('social.platforms.instagram.reach_enabled'),
            'tiktok' => (bool) config('social.platforms.tiktok.stats_enabled'),
            default => false,
        };
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
     * Whether the platform is in limited release: configured and live, but only
     * the limited-release audience may connect it (see config/social → promo_only).
     */
    public function promoOnly(string $platform): bool
    {
        return (bool) config('social.platforms.'.strtolower($platform).'.promo_only', false);
    }

    /**
     * The limited-release audience: promotional customers and admins. Paying
     * customers wait for the platform to open. A null user (console, queue,
     * unauthenticated) is not in it.
     */
    public function inLimitedReleaseAudience(?User $user): bool
    {
        return $user !== null && ($user->isPromotional() || $user->isAdmin());
    }

    /**
     * Whether this user may connect the platform right now. `isConfigured()`
     * answers "is it live at all"; this adds the limited-release audience, so
     * it is the check every connect path uses. A null user (console, queue,
     * unauthenticated) gets the restricted answer.
     */
    public function isAvailableTo(?User $user, string $platform): bool
    {
        if (! $this->isConfigured($platform)) {
            return false;
        }

        return ! $this->promoOnly($platform) || $this->inLimitedReleaseAudience($user);
    }

    /**
     * Metadata describing every platform, for the frontend connect screen.
     * Pass the viewer so `available` reflects limited-release platforms.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalog(?User $user = null): array
    {
        return collect($this->platforms())->map(function (string $platform) use ($user) {
            $config = config("social.platforms.{$platform}", []);

            return [
                'platform' => $platform,
                'label' => $config['label'] ?? ucfirst($platform),
                'enabled' => (bool) ($config['enabled'] ?? true),
                'configured' => $this->isConfigured($platform),
                // What the connect screen renders from: `configured` stays
                // honest about credentials, this is the per-user answer.
                'available' => $this->isAvailableTo($user, $platform),
                'uses_oauth' => $this->for($platform)->usesOAuth(),
                // Our handle when the connect page should offer "Follow us".
                'follow_us' => app(FollowUs::class)->handle($platform),
            ];
        })->all();
    }
}
