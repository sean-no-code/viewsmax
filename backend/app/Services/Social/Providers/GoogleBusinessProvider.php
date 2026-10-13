<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\OAuthResult;
use App\Services\Social\Data\PublishResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Google Business Profile posts ("local posts"). Each managed location is
 * connected as its own account so the user can post to a specific storefront.
 *
 * Three Google APIs are involved: Account Management lists the Business
 * Profile accounts, Business Information lists their locations, and the v4
 * My Business API creates the posts. With `api_base` set, all three go to
 * one local mock of Google's published API description instead.
 *
 * Post options (SocialPost::options, from the post target):
 *   topic_type   STANDARD (an update, default) | EVENT | OFFER
 *   cta_type     BOOK | ORDER | SHOP | LEARN_MORE | SIGN_UP | CALL — the
 *                button; none when empty. Google ignores it on offers.
 *   cta_url      where the button goes (not used by CALL)
 *   event_title, event_start, event_end   required for EVENT and OFFER;
 *                start/end are the business's local wall-clock time,
 *                "YYYY-MM-DDTHH:MM", sent as typed (no time zone conversion)
 *   coupon_code, redeem_url, terms        optional OFFER details
 */
class GoogleBusinessProvider extends GoogleOAuthProvider
{
    protected string $platform = 'google_business';

    public const TOPIC_TYPES = ['STANDARD', 'EVENT', 'OFFER'];

    public const CTA_TYPES = ['BOOK', 'ORDER', 'SHOP', 'LEARN_MORE', 'SIGN_UP', 'CALL'];

    protected function host(string $service): string
    {
        $base = $this->config('api_base');

        return $base ? rtrim($base, '/') : "https://{$service}.googleapis.com";
    }

    public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection
    {
        return $this->storeLocations($user, $this->exchangeGoogleCode($code, $redirectUri));
    }

    /**
     * Store every location the Google account can post to, one account row
     * each. Locations Google says can't take posts are skipped.
     *
     * @return Collection<int, SocialAccount>
     */
    public function storeLocations(User $user, OAuthResult $tokens): Collection
    {
        $accounts = collect();

        foreach ($this->paged($tokens->accessToken, $this->host('mybusinessaccountmanagement').'/v1/accounts', [], 'accounts', 'list Business Profile accounts') as $gbpAccount) {
            $parent = $gbpAccount['name'] ?? null; // accounts/{id}
            if (! $parent) {
                continue;
            }

            $locations = $this->paged($tokens->accessToken, $this->host('mybusinessbusinessinformation')."/v1/{$parent}/locations", [
                'readMask' => 'name,title,languageCode,storefrontAddress,websiteUri,metadata',
                'pageSize' => 100,
            ], 'locations', 'list locations');

            foreach ($locations as $location) {
                $locationName = $location['name'] ?? null; // locations/{id}
                if (! $locationName || data_get($location, 'metadata.canOperateLocalPost') === false) {
                    continue;
                }

                $accounts->push($this->storeAccount($user, [
                    'platform_account_id' => $locationName,
                    'name' => $location['title'] ?? $gbpAccount['accountName'] ?? null,
                    'profile_url' => data_get($location, 'metadata.mapsUri') ?? $location['websiteUri'] ?? null,
                    'access_token' => $tokens->accessToken,
                    'refresh_token' => $tokens->refreshToken,
                    'token_expires_at' => $tokens->expiresAt(),
                    'scopes' => $tokens->scopes,
                    'metadata' => [
                        'account_name' => $parent,
                        'location_name' => $locationName,
                        // The v4 local posts parent is accounts/{aid}/locations/{lid}.
                        'post_parent' => $parent.'/'.$locationName,
                        'language_code' => $location['languageCode'] ?? null,
                        'address' => $this->addressLine($location),
                    ],
                ]));
            }
        }

        // A location reachable through two accounts (e.g. a personal account
        // and a location group) is stored once.
        return $accounts->unique('id')->values();
    }

    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        $parent = $account->meta('post_parent');
        if (! $parent) {
            return PublishResult::failure('Google Business Profile location is missing; reconnect the account.');
        }

        $o = (array) ($post->options ?? []);
        $topic = strtoupper((string) ($o['topic_type'] ?? '')) ?: 'STANDARD';
        if (! in_array($topic, self::TOPIC_TYPES, true)) {
            return PublishResult::failure("Unknown Google post type {$topic}.");
        }

        $body = [
            'languageCode' => $account->meta('language_code') ?: 'en',
            'summary' => (string) $post->content,
            'topicType' => $topic,
        ];

        // Google takes photos by public URL; videos aren't sent.
        if ($image = $this->firstImageUrl($post)) {
            $body['media'] = [['mediaFormat' => 'PHOTO', 'sourceUrl' => $image]];
        }

        if ($topic !== 'OFFER') {
            $cta = strtoupper((string) ($o['cta_type'] ?? ''));
            if ($cta !== '') {
                if (! in_array($cta, self::CTA_TYPES, true)) {
                    return PublishResult::failure("Unknown Google button type {$cta}.");
                }
                $body['callToAction'] = $cta === 'CALL'
                    ? ['actionType' => 'CALL']
                    : ['actionType' => $cta, 'url' => (string) ($o['cta_url'] ?? '')];
            } elseif ($post->link) {
                // Posts made through /api/social/posts carry only a link.
                $body['callToAction'] = ['actionType' => 'LEARN_MORE', 'url' => $post->link];
            }
        }

        if (in_array($topic, ['EVENT', 'OFFER'], true)) {
            $schedule = $this->schedule($o['event_start'] ?? null, $o['event_end'] ?? null);
            if ($schedule === null || blank($o['event_title'] ?? null)) {
                return PublishResult::failure('A Google event or offer needs a title, a start and an end.');
            }
            $body['event'] = ['title' => (string) $o['event_title'], 'schedule' => $schedule];
        }

        if ($topic === 'OFFER') {
            $offer = array_filter([
                'couponCode' => $o['coupon_code'] ?? null,
                'redeemOnlineUrl' => $o['redeem_url'] ?? null,
                'termsConditions' => $o['terms'] ?? null,
            ], fn ($v) => ! blank($v));
            if ($offer) {
                $body['offer'] = $offer;
            }
        }

        $response = Http::withToken($account->access_token)
            ->post($this->host('mybusiness')."/v4/{$parent}/localPosts", $body);

        if (! $response->successful()) {
            Log::warning('Google Business publish failed', [
                'account_id' => $account->id, 'status' => $response->status(), 'body' => $response->body(),
            ]);

            return PublishResult::failure('Google Business Profile publish failed: '.$this->googleError($response), $response->json() ?? []);
        }

        $data = $response->json() ?? [];

        return PublishResult::success($data['name'] ?? null, $data['searchUrl'] ?? null, $data);
    }

    /**
     * Google's TimeInterval needs all four parts. "YYYY-MM-DDTHH:MM" in, the
     * same wall-clock values out; null when either end is missing or unreadable.
     */
    public function schedule(?string $start, ?string $end): ?array
    {
        $parse = function (?string $value): ?Carbon {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value)) {
                return null;
            }

            try {
                return Carbon::createFromFormat('Y-m-d H:i', substr(str_replace('T', ' ', $value), 0, 16));
            } catch (\Throwable) {
                return null;
            }
        };

        $from = $parse($start);
        $to = $parse($end);
        if (! $from || ! $to || $to->lessThanOrEqualTo($from)) {
            return null;
        }

        $date = fn (Carbon $c) => ['year' => $c->year, 'month' => $c->month, 'day' => $c->day];
        $time = fn (Carbon $c) => ['hours' => $c->hour, 'minutes' => $c->minute];

        return [
            'startDate' => $date($from), 'startTime' => $time($from),
            'endDate' => $date($to), 'endTime' => $time($to),
        ];
    }

    /**
     * Every item across all pages of a Google list call.
     */
    protected function paged(string $token, string $url, array $query, string $key, string $action): array
    {
        $items = [];
        $pageToken = null;

        do {
            $response = Http::withToken($token)->get($url, array_filter($query + ['pageToken' => $pageToken]));
            if (! $response->successful()) {
                throw new RuntimeException("Google couldn't {$action}: ".$this->googleError($response));
            }

            $items = array_merge($items, $response->json($key) ?? []);
            $pageToken = $response->json('nextPageToken');
        } while ($pageToken);

        return $items;
    }

    /** Google's own error message, or the status when there is none. */
    protected function googleError(Response $response): string
    {
        return (string) ($response->json('error.message') ?: 'HTTP '.$response->status());
    }

    /** "12 Main St, Springfield": tells apart two branches with one name. */
    protected function addressLine(array $location): ?string
    {
        $address = $location['storefrontAddress'] ?? [];
        $line = trim(implode(', ', array_filter([
            $address['addressLines'][0] ?? null,
            $address['locality'] ?? null,
        ])));

        return $line !== '' ? $line : null;
    }
}
