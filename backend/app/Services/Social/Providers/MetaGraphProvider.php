<?php

namespace App\Services\Social\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared behaviour for Meta's Graph API platforms (Facebook & Instagram), which
 * authenticate through the same Facebook Login flow and Page access tokens.
 */
abstract class MetaGraphProvider extends AbstractSocialProvider
{
    protected function graphVersion(): string
    {
        return $this->config('graph_version', 'v21.0');
    }

    protected function graphBase(): string
    {
        return 'https://graph.facebook.com/'.$this->graphVersion();
    }

    public function getAuthorizationUrl(string $redirectUri, string $state, array $options = []): string
    {
        $params = http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => $this->scopeString(','),
        ]);

        return 'https://www.facebook.com/'.$this->graphVersion().'/dialog/oauth?'.$params;
    }

    /**
     * Exchange the code for a short-lived user token, then upgrade it to a
     * long-lived (≈60 day) token. Returns the long-lived user access token.
     */
    protected function getLongLivedUserToken(string $code, string $redirectUri): string
    {
        $short = Http::get($this->graphBase().'/oauth/access_token', [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if (! $short->successful()) {
            Log::error('['.ucfirst($this->platform).'] token exchange failed', [
                'http_status' => $short->status(),
                'body' => $short->body(),
            ]);
            $this->fail('token exchange', $short->status(), $short->body());
        }

        $shortToken = $short->json('access_token');
        Log::info('['.ucfirst($this->platform).'] short-lived token obtained', [
            'has_token' => ! empty($shortToken),
        ]);

        $long = Http::get($this->graphBase().'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'fb_exchange_token' => $shortToken,
        ]);

        if (! $long->successful()) {
            // Fall back to the short-lived token if the upgrade fails.
            return $shortToken;
        }

        return $long->json('access_token', $shortToken);
    }

    /**
     * Fetch the Pages the user manages, each carrying its own Page access token.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getManagedPages(string $userToken): array
    {
        $pages = [];
        $url = $this->graphBase().'/me/accounts';
        $params = [
            'access_token' => $userToken,
            'fields' => 'id,name,access_token,category,picture{url},link',
            'limit' => 100,
        ];

        // Follow pagination so users with many Pages get them all.
        do {
            $response = Http::get($url, $params);
            if (! $response->successful()) {
                $this->fail('fetch pages', $response->status(), $response->body());
            }

            $data = $response->json();
            $pages = array_merge($pages, $data['data'] ?? []);

            $url = $data['paging']['next'] ?? null;
            $params = []; // the `next` URL already carries all query params
        } while ($url);

        return $pages;
    }
}
