<?php

namespace App\Services\Social\Providers;

use App\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\MetaGraphOutcome;
use App\Services\Social\Data\PublishResult;
use App\Services\Social\MetaGraphErrors;
use App\Services\Social\SocialConnectException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
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
     * Facebook-specific Graph errors layered over MetaGraphErrors' generic
     * rules (key => [kind, message]).
     * A numeric key matches error_subcode, then error.code, then a standalone
     * number in the body (33 never matches 330); text keys match anywhere.
     * Order only matters for the body search: first hit wins.
     */
    public const ERRORS = [
        // --- the token cannot publish as this Page: flag the account for reconnect
        'Error validating access token' => [MetaGraphOutcome::REAUTH, 'Facebook no longer accepts this Page\'s access token. Reconnect the Page in Settings, then retry the post.'],
        'REVOKED_ACCESS_TOKEN' => [MetaGraphOutcome::REAUTH, 'Facebook revoked this Page\'s access token. Reconnect the Page in Settings, then retry the post.'],
        // The token is valid but belongs to the user, not to the Page.
        'Unpublished posts must be posted to a page as the page itself' => [MetaGraphOutcome::REAUTH, 'Facebook will not let this account publish as the Page. Reconnect the Page in Settings, then retry the post.'],
        1404078 => [MetaGraphOutcome::REAUTH, 'Facebook needs the Page publishing authorization again. Reconnect the Page in Settings, then retry the post.'],
        'must be granted before impersonating' => [MetaGraphOutcome::REAUTH, 'Facebook Page permissions are missing. Reconnect the Page in Settings and allow all permissions, then retry the post.'],
        490 => [MetaGraphOutcome::REAUTH, 'Facebook says the access token has expired. Reconnect the Page in Settings, then retry the post.'],

        // --- transient: Meta documents 368/1390008 as "wait and retry"; the
        // HTML outage page comes back with no JSON at all
        1390008 => [MetaGraphOutcome::RETRY, 'Facebook has temporarily blocked posting from this Page (posting too fast); the post will be retried.'],
        'Sorry, something went wrong' => [MetaGraphOutcome::RETRY, 'Facebook is temporarily unavailable; the post will be retried.'],

        // --- final rejections with a readable reason
        1366046 => [MetaGraphOutcome::FAIL, 'Facebook rejected the photo: photos must be smaller than 4 MB and saved as JPG or PNG.'],
        1346003 => [MetaGraphOutcome::FAIL, 'Facebook flagged the content as abusive.'],
        1404006 => [MetaGraphOutcome::FAIL, 'Facebook requires a security check before it will publish this post. Log in at facebook.com, complete it, then try again.'],
        2069019 => [MetaGraphOutcome::FAIL, 'Facebook rejected the media file as invalid.'],
        1404102 => [MetaGraphOutcome::FAIL, 'Facebook says the content violates its Community Standards.'],
        1366051 => [MetaGraphOutcome::FAIL, 'Facebook says these photos were already posted.'],
        1609008 => [MetaGraphOutcome::FAIL, 'Facebook does not allow posting facebook.com links.'],
        2061006 => [MetaGraphOutcome::FAIL, 'Facebook rejected a URL in the post content as invalid.'],
        1349125 => [MetaGraphOutcome::FAIL, 'Facebook rejected the content format.'],
        1404112 => [MetaGraphOutcome::FAIL, 'For security reasons, Facebook has limited this account\'s access for a few days. Try again later.'],
        'Name parameter too long' => [MetaGraphOutcome::FAIL, 'Facebook says the post content is too long.'],
        1363047 => [MetaGraphOutcome::FAIL, 'Facebook\'s service is temporarily unavailable. Try again later.'],
        1609010 => [MetaGraphOutcome::FAIL, 'Facebook\'s service is temporarily unavailable. Try again later.'],
        4854002 => [MetaGraphOutcome::FAIL, 'Confirm your identity before you can publish as this Page: open the Facebook app on your phone and follow the instructions, then try again.'],
        '(#100) No permission to publish the video' => [MetaGraphOutcome::FAIL, 'Facebook says this account has no permission to publish the video.'],
        459 => [MetaGraphOutcome::FAIL, 'Facebook is asking you to resolve a security check. Log in at facebook.com, complete it, then try again.'],
        492 => [MetaGraphOutcome::FAIL, 'Your Facebook user no longer has a role on this Page. Ask a Page admin to grant you a role, then reconnect the Page.'],
        33 => [MetaGraphOutcome::FAIL, 'The Facebook Page or post this was targeting no longer exists. Reconnect the Page and schedule again.'],
        '(#200)' => [MetaGraphOutcome::FAIL, 'Facebook rejected the post due to missing permissions. Make sure your Facebook account has full content access to the Page, then reconnect the Page.'],
    ];

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

    /**
     * @throws TransientPublishException when Facebook asks us to retry later
     */
    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        $pageId = $account->platform_account_id;
        $token = $account->access_token;
        $message = (string) $post->content;
        $images = array_column($this->mediaItems($post, 'image'), 'url');
        $video = $this->firstVideoUrl($post);

        if ($video) {
            // Publish a video to the Page.
            $response = Http::post($this->graphBase()."/{$pageId}/videos", [
                'file_url' => $video,
                'description' => $message,
                'access_token' => $token,
            ]);
        } elseif ($images) {
            // Publish a photo with optional caption.
            $response = Http::post($this->graphBase()."/{$pageId}/photos", array_filter([
                'url' => $images[0],
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
            return $this->rejected($account, $response);
        }

        $data = $response->json();
        $postId = $data['post_id'] ?? $data['id'] ?? null;

        return PublishResult::success(
            $postId,
            $postId ? "https://www.facebook.com/{$postId}" : null,
            $data
        );
    }

    /**
     * Turn a failed Graph response into a PublishResult: flag the Page for
     * reconnect when the token is dead, hand transient errors to the queue,
     * otherwise fail with a message the user can act on. Only ever called
     * before a create call has succeeded, so a retry never double-posts.
     *
     * @throws TransientPublishException
     */
    private function rejected(SocialAccount $account, Response $response): PublishResult
    {
        $outcome = $this->outcome($response)->throwIfRetry();

        if ($outcome->needsReauth()) {
            $account->markNeedsReauth($outcome->message);
        }

        Log::warning('[Facebook] publish rejected', [
            'account_id' => $account->id,
            'kind' => $outcome->kind,
            'reason' => $outcome->raw,
        ]);

        return PublishResult::failure($outcome->message, $response->json() ?? []);
    }

    /**
     * Numeric ERRORS keys fall back to a whole-number search of the body, and
     * fbtrace_id is a random alphanumeric string that can contain "33" or
     * "459" by chance, so it is dropped from the searched text. A non-JSON
     * body (the HTML outage page) is searched as-is.
     */
    private function outcome(Response $response): MetaGraphOutcome
    {
        $body = $response->json();
        $haystack = is_array($body)
            ? (string) json_encode(Arr::except($body, ['error.fbtrace_id']))
            : $response->body();

        return MetaGraphErrors::classify($response->status(), is_array($body) ? $body : null, self::ERRORS, $haystack);
    }
}
