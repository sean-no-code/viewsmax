<?php

namespace App\Services\Social\Contracts;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\PublishResult;
use Illuminate\Support\Collection;

interface SocialProviderInterface
{
    /**
     * The platform key (e.g. "facebook"). Matches config('social.platforms.*').
     */
    public function key(): string;

    /**
     * Whether this provider uses the standard redirect-based OAuth flow.
     * Providers that authenticate differently (e.g. Bluesky app passwords)
     * return false and implement connectWithCredentials() instead.
     */
    public function usesOAuth(): bool;

    /**
     * Build the authorization URL the user is redirected to in order to grant
     * access. $state is an opaque CSRF/session token round-tripped by the caller.
     * $options may carry a PKCE code_challenge, etc.
     */
    public function getAuthorizationUrl(string $redirectUri, string $state, array $options = []): string;

    /**
     * Exchange an authorization code for tokens, then create/update one or more
     * SocialAccount rows for the user (a single OAuth grant can yield several
     * accounts, e.g. multiple Facebook Pages). Returns the connected accounts.
     *
     * @param  array  $options  May include code_verifier (PKCE), redirect_uri override.
     * @return Collection<int, SocialAccount>
     */
    public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection;

    /**
     * Connect using direct credentials for non-OAuth providers (e.g. Bluesky).
     *
     * @return Collection<int, SocialAccount>
     */
    public function connectWithCredentials(User $user, array $credentials): Collection;

    /**
     * Ensure the account has a usable access token, refreshing if needed and
     * supported. Returns the (possibly refreshed) account. Throws on hard failure.
     */
    public function ensureFreshToken(SocialAccount $account): SocialAccount;

    /**
     * Publish a post's content/media to the given account.
     */
    public function publish(SocialAccount $account, SocialPost $post): PublishResult;

    /**
     * Current follower/subscriber count, or null when the platform/API plan
     * doesn't expose it. Consumed by audience:refresh.
     */
    public function fetchFollowerCount(SocialAccount $account): ?int;

    /**
     * Engagement metrics for the given remote post ids, keyed by id →
     * ['likes','comments','shares','views']. Only ids the platform returns data
     * for appear. Consumed by posts:refresh-metrics.
     *
     * @param  array<int, string>  $remotePostIds
     * @return array<string, array{likes:int, comments:int, shares:int, views:int}>
     */
    public function fetchPostMetrics(SocialAccount $account, array $remotePostIds): array;
}
