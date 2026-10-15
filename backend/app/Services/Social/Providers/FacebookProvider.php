<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\PublishResult;
use App\Services\Social\SocialConnectException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookProvider extends MetaGraphProvider
{
    protected string $platform = 'facebook';

    /**
     * A Page listed through a business the user has no role on comes back
     * from /me/accounts without a Page token; storing it anyway looked
     * connected while every publish failed.
     */
    public const MESSAGE_NO_PAGE_TOKEN = 'Your Facebook user has no permission to manage this page. Ask a page admin for full content access, then reconnect';

    /**
     * Connect every Facebook Page the user manages as a separate account.
     *
     * @throws SocialConnectException when no listed Page carries a Page token
     */
    public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection
    {
        $userToken = $this->getLongLivedUserToken($code, $redirectUri);
        [$pages, $withoutToken] = collect($this->getManagedPages($userToken))
            ->partition(fn (array $page) => ! empty($page['access_token']));

        if ($withoutToken->isNotEmpty()) {
            Log::warning('[Facebook] pages listed without a page token were skipped', [
                'pages' => $withoutToken->map(fn (array $page) => ($page['name'] ?? '?').' ('.$page['id'].')')->values()->all(),
                'reason' => self::MESSAGE_NO_PAGE_TOKEN,
            ]);

            if ($pages->isEmpty()) {
                throw new SocialConnectException(self::MESSAGE_NO_PAGE_TOKEN);
            }
        }

        return $pages->values()->map(function (array $page) use ($user) {
            return $this->storeAccount($user, [
                'platform_account_id' => $page['id'],
                'name' => $page['name'] ?? null,
                'username' => $page['name'] ?? null,
                'avatar_url' => data_get($page, 'picture.data.url'),
                'profile_url' => $page['link'] ?? ('https://facebook.com/'.$page['id']),
                // Page tokens derived from a long-lived user token do not expire.
                'access_token' => $page['access_token'],
                'token_expires_at' => null,
                'scopes' => $this->config('scopes', []),
                'metadata' => [
                    'page_id' => $page['id'],
                    'category' => $page['category'] ?? null,
                ],
            ]);
        });
    }

    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        $pageId = $account->platform_account_id;
        $token = $account->access_token;
        $message = (string) $post->content;
        $image = $this->firstImageUrl($post);
        $video = $this->firstVideoUrl($post);

        if ($video) {
            // Publish a video to the Page.
            $response = Http::post($this->graphBase()."/{$pageId}/videos", [
                'file_url' => $video,
                'description' => $message,
                'access_token' => $token,
            ]);
        } elseif ($image) {
            // Publish a photo with optional caption.
            $response = Http::post($this->graphBase()."/{$pageId}/photos", array_filter([
                'url' => $image,
                'caption' => $message,
                'access_token' => $token,
            ]));
        } else {
            // Plain text / link status update.
            $response = Http::post($this->graphBase()."/{$pageId}/feed", array_filter([
                'message' => $message,
                'link' => $post->link,
                'access_token' => $token,
            ]));
        }

        if (! $response->successful()) {
            return PublishResult::failure('Facebook publish failed: '.$response->body(), $response->json() ?? []);
        }

        $data = $response->json();
        $postId = $data['post_id'] ?? $data['id'] ?? null;

        return PublishResult::success(
            $postId,
            $postId ? "https://www.facebook.com/{$postId}" : null,
            $data
        );
    }
}
