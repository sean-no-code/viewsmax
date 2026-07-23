<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\PublishResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Google My Business (Business Profile) local posts. Each managed location is
 * connected as its own account so the user can post to a specific storefront.
 */
class GoogleBusinessProvider extends GoogleOAuthProvider
{
    protected string $platform = 'google_business';

    public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection
    {
        $tokens = $this->exchangeGoogleCode($code, $redirectUri);
        $accounts = collect();

        // 1. List the Business Profile accounts the user manages.
        $accountList = Http::withToken($tokens->accessToken)
            ->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts')
            ->json('accounts') ?? [];

        foreach ($accountList as $gmbAccount) {
            $accountName = $gmbAccount['accountName'] ?? null; // accounts/{id}
            $parent = $gmbAccount['name'] ?? null;
            if (! $parent) {
                continue;
            }

            // 2. List the locations under each account.
            $locations = Http::withToken($tokens->accessToken)
                ->get("https://mybusinessbusinessinformation.googleapis.com/v1/{$parent}/locations", [
                    'readMask' => 'name,title,storefrontAddress,websiteUri',
                    'pageSize' => 100,
                ])->json('locations') ?? [];

            foreach ($locations as $location) {
                $locationName = $location['name'] ?? null; // locations/{id}
                if (! $locationName) {
                    continue;
                }

                // The v4 local posts parent is accounts/{aid}/locations/{lid}.
                $postParent = $parent.'/'.$locationName;

                $accounts->push($this->storeAccount($user, [
                    'platform_account_id' => $locationName,
                    'name' => $location['title'] ?? $accountName,
                    'username' => $location['title'] ?? null,
                    'profile_url' => $location['websiteUri'] ?? null,
                    'access_token' => $tokens->accessToken,
                    'refresh_token' => $tokens->refreshToken,
                    'token_expires_at' => $tokens->expiresAt(),
                    'scopes' => $tokens->scopes,
                    'metadata' => [
                        'account_name' => $parent,
                        'location_name' => $locationName,
                        'post_parent' => $postParent,
                    ],
                ]));
            }
        }

        return $accounts;
    }

    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        $parent = $account->meta('post_parent');
        if (! $parent) {
            return PublishResult::failure('Google Business location is missing; reconnect the account.');
        }

        $body = [
            'languageCode' => 'en-US',
            'summary' => (string) $post->content,
            'topicType' => 'STANDARD',
        ];

        $image = $this->firstImageUrl($post);
        if ($image) {
            $body['media'] = [[
                'mediaFormat' => 'PHOTO',
                'sourceUrl' => $image,
            ]];
        }

        if ($post->link) {
            $body['callToAction'] = [
                'actionType' => 'LEARN_MORE',
                'url' => $post->link,
            ];
        }

        $response = Http::withToken($account->access_token)
            ->post("https://mybusiness.googleapis.com/v4/{$parent}/localPosts", $body);

        if (! $response->successful()) {
            return PublishResult::failure('Google Business publish failed: '.$response->body(), $response->json() ?? []);
        }

        $data = $response->json();

        return PublishResult::success($data['name'] ?? null, $data['searchUrl'] ?? null, $data);
    }
}
