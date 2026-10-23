<?php

namespace App\Services\Social\Providers;

use App\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Bluesky\BlobImage;
use App\Services\Social\Bluesky\NothingPublishedException;
use App\Services\Social\Bluesky\RichTextFacets;
use App\Services\Social\Contracts\SupportsComments;
use App\Services\Social\Contracts\SupportsFollowing;
use App\Services\Social\Data\CommentResult;
use App\Services\Social\Data\PublishResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Bluesky publishing via the AT Protocol XRPC API. Bluesky does not use OAuth;
 * the user authenticates with their handle and an app password.
 *
 * Error policy: everything before createRecord (session refresh,
 * image upload) may ask the queue to retry on 429/5xx/network because nothing
 * has been published yet. createRecord itself retries on the same conditions
 * and fails fast on any other 4xx; once it has answered 2xx nothing throws.
 */
class BlueskyProvider extends AbstractSocialProvider implements SupportsComments, SupportsFollowing
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
        // People type their handle the way Bluesky displays it, "@you.bsky.social".
        // Bluesky treats any identifier containing "@" as an email address, so a
        // leading "@" fails login with "InvalidEmail: local part cannot be empty".
        // A real email ("you@example.com") never starts with "@" and is unaffected.
        $identifier = ltrim(trim($credentials['identifier'] ?? $credentials['handle'] ?? ''), '@');
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
            try {
                $response = Http::withToken($account->refresh_token)
                    ->send('POST', $this->serviceUrl().'/xrpc/com.atproto.server.refreshSession');
            } catch (ConnectionException) {
                throw $this->unreachable('session refresh');
            }

            if (! $response->successful()) {
                // A rate limit / outage here must retry the whole job: going on
                // with the expired token would hit ExpiredToken on publish and
                // wrongly flag a healthy account for reconnect.
                $this->throwIfTransient($response, 'session refresh');

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
        try {
            $record = $this->buildRecord($account, (string) $post->content, $this->mediaItems($post, 'image'));
        } catch (NothingPublishedException $e) {
            return PublishResult::failure($e->getMessage());
        }

        try {
            $response = $this->createRecord($account, $record);
        } catch (ConnectionException) {
            throw $this->unreachable('publish');
        }

        if (! $response->successful()) {
            if ($this->failReauthIfUnauthorized($account, $response, 'publish', $this->tokenRejected())) {
                return PublishResult::failure((string) $account->fresh()->last_error);
            }
            $this->throwIfTransient($response, 'publish');

            return PublishResult::failure(
                'Bluesky rejected the post, nothing was published: '.$this->reason($response),
                $response->json() ?? []
            );
        }

        // From here on nothing may throw: the post is live.
        $uri = $response->json('uri'); // at://did/app.bsky.feed.post/{rkey}
        $rkey = $uri ? basename($uri) : null;
        $handle = $account->meta('handle');
        $url = ($handle && $rkey) ? "https://bsky.app/profile/{$handle}/post/{$rkey}" : null;

        return PublishResult::success($uri, $url, $response->json());
    }

    /**
     * Reply to a published post or to the previous comment in the chain (row
     * 117). Bluesky needs the parent's cid and the thread root's uri/cid, so
     * the parent is read back with getPostThread first. Comments never retry
     * (PostTargetCommentJob tries = 1), so every failure is a CommentResult.
     */
    public function comment(SocialAccount $account, string $remotePostId, string $body, ?string $previousRemoteId = null): CommentResult
    {
        $parentUri = $previousRemoteId ?: $remotePostId;

        try {
            $thread = Http::withToken($account->access_token)
                ->get($this->serviceUrl().'/xrpc/app.bsky.feed.getPostThread', ['uri' => $parentUri, 'depth' => 0]);
        } catch (ConnectionException) {
            return CommentResult::failure('Bluesky could not be reached, the comment was not posted.');
        }

        if (! $thread->successful()) {
            return CommentResult::failure('Could not load the Bluesky post to reply to: '.$this->reason($thread));
        }

        $parent = $thread->json('thread.post') ?? [];
        if (empty($parent['cid'])) {
            return CommentResult::failure('Could not load the Bluesky post to reply to: no cid in the response.');
        }
        $root = $parent['record']['reply']['root'] ?? ['uri' => $parentUri, 'cid' => $parent['cid']];

        try {
            $record = $this->buildRecord($account, $body, []);
            $record['reply'] = [
                'root' => ['uri' => $root['uri'], 'cid' => $root['cid']],
                'parent' => ['uri' => $parentUri, 'cid' => $parent['cid']],
            ];
            $response = $this->createRecord($account, $record);
        } catch (ConnectionException) {
            return CommentResult::failure('Bluesky could not be reached, the comment was not posted.');
        } catch (TransientPublishException|NothingPublishedException $e) {
            return CommentResult::failure($e->getMessage());
        }

        if (! $response->successful()) {
            if ($this->failReauthIfUnauthorized($account, $response, 'comment', $this->tokenRejected())) {
                return CommentResult::failure((string) $account->fresh()->last_error);
            }

            return CommentResult::failure('Bluesky rejected the comment: '.$this->reason($response), $response->json() ?? []);
        }

        return CommentResult::success($response->json('uri'), $response->json());
    }

    /**
     * Build an app.bsky.feed.post record: text with its facets and
     * up to 4 images as blob embeds with their aspect ratio.
     *
     * @param  array<int, array{url:string, alt:?string}>  $images
     *
     * @throws NothingPublishedException when an image can't be prepared
     * @throws TransientPublishException on a 429/5xx/network error during upload
     */
    protected function buildRecord(SocialAccount $account, string $text, array $images): array
    {
        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => now()->toIso8601String(),
        ];

        $facets = RichTextFacets::detect($text, fn (string $handle) => $this->resolveHandle($account, $handle));
        if ($facets !== []) {
            $record['facets'] = $facets;
        }

        $embedImages = [];
        foreach (array_slice($images, 0, 4) as $img) {
            $embedImages[] = $this->uploadImage($account, $img);
        }
        if ($embedImages !== []) {
            $record['embed'] = [
                '$type' => 'app.bsky.embed.images',
                'images' => $embedImages,
            ];
        }

        return $record;
    }

    /**
     * @throws ConnectionException
     */
    protected function createRecord(SocialAccount $account, array $record): Response
    {
        return Http::withToken($account->access_token)->post($this->serviceUrl().'/xrpc/com.atproto.repo.createRecord', [
            'repo' => $account->meta('did') ?? $account->platform_account_id,
            'collection' => 'app.bsky.feed.post',
            'record' => $record,
        ]);
    }

    /**
     * Download, shrink under the blob limit and upload one image, returning
     * the embed entry. A failed image fails the post instead of silently going
     * out without it: "I posted a picture" must mean a picture.
     *
     * @param  array{url:string, alt:?string}  $img
     * @return array{alt:string, image:array, aspectRatio:array{width:int, height:int}}
     */
    protected function uploadImage(SocialAccount $account, array $img): array
    {
        $binary = $this->fetchMedia($img['url']);
        if ($binary === null) {
            throw $this->nothingPublished("could not download {$img['url']}");
        }

        try {
            $prepared = BlobImage::prepare($binary['body'], $binary['content_type']);
        } catch (RuntimeException $e) {
            throw $this->nothingPublished($e->getMessage());
        }

        try {
            $response = Http::withToken($account->access_token)
                ->withBody($prepared['body'], $prepared['mime'])
                ->post($this->serviceUrl().'/xrpc/com.atproto.repo.uploadBlob');
        } catch (ConnectionException) {
            throw $this->unreachable('image upload');
        }

        if (! $response->successful()) {
            if ($this->failReauthIfUnauthorized($account, $response, 'image upload', $this->tokenRejected())) {
                throw new NothingPublishedException((string) $account->fresh()->last_error);
            }
            $this->throwIfTransient($response, 'image upload');
            throw $this->nothingPublished('image upload failed: '.$this->reason($response));
        }

        $blob = $response->json('blob');
        if (! is_array($blob)) {
            throw $this->nothingPublished('Bluesky returned no blob reference for the image');
        }

        return [
            'alt' => $img['alt'] ?? '',
            'image' => $blob,
            'aspectRatio' => ['width' => $prepared['width'], 'height' => $prepared['height']],
        ];
    }

    /**
     * Handle → DID for a mention facet. Any failure means "not a mention":
     * the text goes out unchanged rather than blocking the post.
     */
    protected function resolveHandle(SocialAccount $account, string $handle): ?string
    {
        try {
            $response = Http::withToken($account->access_token)
                ->get($this->serviceUrl().'/xrpc/com.atproto.identity.resolveHandle', ['handle' => $handle]);
        } catch (ConnectionException) {
            return null;
        }

        $did = $response->successful() ? $response->json('did') : null;
        if (! is_string($did) || $did === '') {
            Log::info('[bluesky] mention left as plain text; handle did not resolve', ['handle' => $handle, 'status' => $response->status()]);

            return null;
        }

        return $did;
    }

    /**
     * Bluesky answers an expired/invalid access JWT with 400 ExpiredToken or
     * 401 InvalidToken/AuthMissing; both mean the session is dead.
     */
    protected function tokenRejected(): callable
    {
        return fn (Response $r) => in_array($r->json('error'), ['ExpiredToken', 'InvalidToken', 'AuthMissing'], true);
    }

    /** XRPC error bodies are {error, message}; fall back to a body excerpt. */
    protected function reason(Response $response): string
    {
        $reason = $response->json('message') ?: $response->json('error') ?: $this->bodyExcerpt($response);

        return trim((string) $reason)." (HTTP {$response->status()})";
    }

    protected function nothingPublished(string $reason): NothingPublishedException
    {
        return new NothingPublishedException("Could not prepare the post for Bluesky, nothing was published: {$reason}");
    }

    protected function unreachable(string $action): TransientPublishException
    {
        Log::warning("[bluesky] {$action} could not reach Bluesky; queue will retry");

        return new TransientPublishException("Bluesky could not be reached during {$action} — retrying automatically.");
    }
}
