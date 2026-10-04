<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Contracts\SupportsFollowing;
use App\Services\Social\Data\PublishResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Bluesky publishing via the AT Protocol XRPC API. Bluesky does not use OAuth;
 * the user authenticates with their handle and an app password.
 */
class BlueskyProvider extends AbstractSocialProvider implements SupportsFollowing
{
    protected string $platform = 'bluesky';

    public function usesOAuth(): bool
    {
        return false;
    }

    public function getAuthorizationUrl(string $redirectUri, string $state, array $options = []): string
    {
        throw new RuntimeException('Bluesky uses handle + app password, not OAuth.');
    }

    public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection
    {
        throw new RuntimeException('Bluesky uses handle + app password, not OAuth.');
    }

    protected function serviceUrl(): string
    {
        return rtrim($this->config('service_url', 'https://bsky.social'), '/');
    }

    /**
     * @param  array{identifier:string, password:string}  $credentials
     */
    public function connectWithCredentials(User $user, array $credentials): Collection
    {
        $identifier = trim($credentials['identifier'] ?? $credentials['handle'] ?? '');
        $password = $credentials['password'] ?? $credentials['app_password'] ?? '';

        if ($identifier === '' || $password === '') {
            throw new RuntimeException('Bluesky requires a handle and app password.');
        }

        $session = Http::post($this->serviceUrl().'/xrpc/com.atproto.server.createSession', [
            'identifier' => $identifier,
            'password' => $password,
        ]);

        if (! $session->successful()) {
            $this->fail('login', $session->status(), $session->body());
        }

        $data = $session->json();

        $account = $this->storeAccount($user, [
            'platform_account_id' => $data['did'] ?? null,
            'name' => $data['handle'] ?? $identifier,
            'username' => $data['handle'] ?? $identifier,
            'profile_url' => isset($data['handle']) ? 'https://bsky.app/profile/'.$data['handle'] : null,
            'access_token' => $data['accessJwt'] ?? null,
            'refresh_token' => $data['refreshJwt'] ?? null,
            'token_expires_at' => $this->accessExpiry($data['accessJwt'] ?? null),
            'metadata' => [
                'did' => $data['did'] ?? null,
                'handle' => $data['handle'] ?? $identifier,
                'service_url' => $this->serviceUrl(),
            ],
        ]);

        return collect([$account]);
    }

    public function follow(SocialAccount $account, string $handle): void
    {
        $token = $account->access_token;

        $did = Http::withToken($token)
            ->get($this->serviceUrl().'/xrpc/com.atproto.identity.resolveHandle', ['handle' => $handle])
            ->json('did');
        if (! $did) {
            throw new RuntimeException("Bluesky account {$handle} not found.");
        }

        $response = Http::withToken($token)->post($this->serviceUrl().'/xrpc/com.atproto.repo.createRecord', [
            'repo' => $account->platform_account_id,
            'collection' => 'app.bsky.graph.follow',
            'record' => [
                '$type' => 'app.bsky.graph.follow',
                'subject' => $did,
                'createdAt' => now()->toIso8601ZuluString(),
            ],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("Bluesky refused the follow (HTTP {$response->status()}): ".$response->body());
        }
    }

    /**
     * When the access JWT expires, read from its own `exp` claim. Bluesky
     * sets the lifetime (two hours on the live service); fall back to one
     * hour only if the token can't be read.
     */
    protected function accessExpiry(?string $jwt): Carbon
    {
        $payload = json_decode((string) base64_decode(strtr(explode('.', (string) $jwt)[1] ?? '', '-_', '+/')), true);

        return isset($payload['exp']) && is_numeric($payload['exp'])
            ? Carbon::createFromTimestamp((int) $payload['exp'])
            : now()->addHour();
    }

    public function ensureFreshToken(SocialAccount $account): SocialAccount
    {
        if ($account->hasValidToken()) {
            return $account;
        }

        if (empty($account->refresh_token)) {
            $account->markNeedsReauth('Bluesky session expired; reconnect with your app password.');

            return $account;
        }

        return $this->refreshingSafely($account, function (SocialAccount $account) {
            // refreshSession takes no input, and Bluesky rejects any request
            // body (even "[]"), so send the POST without one.
            $response = Http::withToken($account->refresh_token)
                ->send('POST', $this->serviceUrl().'/xrpc/com.atproto.server.refreshSession');

            if (! $response->successful()) {
                if ($this->isDefinitiveAuthFailure($response->status())) {
                    Log::warning('Bluesky session refresh rejected', [
                        'account_id' => $account->id, 'status' => $response->status(), 'body' => $response->body(),
                    ]);
                    $account->markNeedsReauth('Bluesky session was rejected — reconnect with your app password.');
                } else {
                    Log::warning('Bluesky session refresh failed transiently', [
                        'account_id' => $account->id, 'status' => $response->status(),
                    ]);
                }

                return $account;
            }

            $data = $response->json();
            $account->update([
                'access_token' => $data['accessJwt'] ?? $account->access_token,
                'refresh_token' => $data['refreshJwt'] ?? $account->refresh_token,
                'token_expires_at' => $this->accessExpiry($data['accessJwt'] ?? null),
                'status' => SocialAccount::STATUS_CONNECTED,
                'last_error' => null,
            ]);

            return $account->refresh();
        });
    }

    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        $did = $account->meta('did') ?? $account->platform_account_id;
        $token = $account->access_token;
        $text = (string) $post->content;

        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => now()->toIso8601String(),
        ];

        // Attach up to 4 images as a blob embed.
        $images = $this->mediaItems($post, 'image');
        $embedImages = [];
        foreach (array_slice($images, 0, 4) as $img) {
            $blob = $this->uploadBlob($account, $img['url']);
            if ($blob) {
                $embedImages[] = [
                    'alt' => $img['alt'] ?? '',
                    'image' => $blob,
                ];
            }
        }

        if (! empty($embedImages)) {
            $record['embed'] = [
                '$type' => 'app.bsky.embed.images',
                'images' => $embedImages,
            ];
        }

        $response = Http::withToken($token)->post($this->serviceUrl().'/xrpc/com.atproto.repo.createRecord', [
            'repo' => $did,
            'collection' => 'app.bsky.feed.post',
            'record' => $record,
        ]);

        if (! $response->successful()) {
            return PublishResult::failure('Bluesky publish failed: '.$response->body(), $response->json() ?? []);
        }

        $uri = $response->json('uri'); // at://did/app.bsky.feed.post/{rkey}
        $rkey = $uri ? basename($uri) : null;
        $handle = $account->meta('handle');
        $url = ($handle && $rkey) ? "https://bsky.app/profile/{$handle}/post/{$rkey}" : null;

        return PublishResult::success($uri, $url, $response->json());
    }

    /**
     * Upload an image and return its blob reference for embedding.
     */
    protected function uploadBlob(SocialAccount $account, string $url): ?array
    {
        $binary = Http::get($url);
        if (! $binary->successful()) {
            return null;
        }

        $response = Http::withToken($account->access_token)
            ->withBody($binary->body(), $binary->header('Content-Type') ?: 'image/jpeg')
            ->post($this->serviceUrl().'/xrpc/com.atproto.repo.uploadBlob');

        return $response->successful() ? $response->json('blob') : null;
    }
}
