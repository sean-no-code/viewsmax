<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\Post;
use App\Models\ShortLink;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Replaces http(s) URLs in post text with tracked /l/{slug} redirects.
 * Idempotent: the same (post, destination) always reuses its slug, so
 * re-saving a draft never mints new links, and already-shortened text passes
 * through untouched (our own short domain is skipped).
 */
class ShortLinkService
{
    /** Scheme-required URL matcher; trailing sentence punctuation excluded. */
    private const URL_REGEX = '~https?://[^\s<>"\']+~iu';

    /** Punctuation glued to a URL's end that is prose, not address. */
    private const TRAILING_PUNCTUATION = '.,;:!?)\']}';

    public function shortenUrlsInText(User $user, ?Post $post, string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }

        $base = $this->baseUrl();

        return preg_replace_callback(self::URL_REGEX, function (array $match) use ($user, $post, $base) {
            $url = rtrim($match[0], self::TRAILING_PUNCTUATION);
            $trailing = substr($match[0], strlen($url));

            // Never re-shorten our own links.
            if (str_starts_with($url, $base.'/l/')) {
                return $match[0];
            }

            $link = ShortLink::firstOrCreate(
                ['user_id' => $user->id, 'post_id' => $post?->id, 'destination_url' => $url],
                ['slug' => $this->uniqueSlug()]
            );

            // Destination is one of the user's offers → bridge into offer
            // attribution: mint (or reuse) a TrackingLink so the redirect can
            // carry ?trk= and the pixel credits clicks/conversions to the offer.
            if (! $link->tracking_link_id && $post) {
                $this->attachOfferTracking($user, $post, $link);
            }

            return $link->shortUrl().$trailing;
        }, $text);
    }

    /**
     * If $link->destination_url matches one of the user's offers, create (or
     * reuse) an auto tracking link on that offer — declared on every platform
     * the post targets — and pin it to the short link.
     */
    private function attachOfferTracking(User $user, Post $post, ShortLink $link): void
    {
        $target = $this->normalizeUrl($link->destination_url);
        $offer = $user->offers()->get()
            ->first(fn (Offer $o) => $o->offer_url && $this->normalizeUrl($o->offer_url) === $target);

        if (! $offer) {
            return;
        }

        // One auto link per (post, offer): a re-saved post reuses it.
        $name = "Post #{$post->id} — auto shortlink";
        $trackingLink = TrackingLink::where('tracking_event_id', $offer->id)
            ->where('name', $name)
            ->first();

        if (! $trackingLink) {
            // Post platforms → placement keys (youtube's placement key is 'video').
            $platforms = $this->payloadPlatforms ?: $post->targets()->pluck('platform')->all();
            $placements = collect($platforms)
                ->map(fn (string $p) => $p === 'youtube' ? TrackingLink::PLACEMENT_VIDEO : $p)
                ->filter(fn (string $p) => in_array($p, TrackingLink::PLACEMENTS, true))
                ->unique()
                ->values()
                ->all();

            $trackingLink = app(TrackingService::class)->createLink($offer->id, [
                'placement' => $placements[0] ?? TrackingLink::PLACEMENT_OTHER,
                'placements' => $placements ?: null,
                'name' => $name,
                'description' => 'Auto-created from a shortened link in this post.',
            ]);
        }

        $link->forceFill(['tracking_link_id' => $trackingLink->id])->save();
    }

    /** Comparable form of a URL: scheme-insensitive host + path, no query/slash. */
    private function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        $host = strtolower(preg_replace('/^www\./i', '', $parts['host'] ?? ''));
        $path = rtrim($parts['path'] ?? '', '/');

        return $host.$path;
    }

    /**
     * The platforms the current payload targets — captured here because
     * shortening runs BEFORE the post's target rows are (re)created, so the
     * auto offer-link can't read them off the post yet.
     *
     * @var array<int, string>
     */
    private array $payloadPlatforms = [];

    /** Shorten every text field of a validated post payload. */
    public function shortenPayload(User $user, Post $post, array $data): array
    {
        $this->payloadPlatforms = array_values(array_unique(
            array_column($data['targets'] ?? [], 'platform')
                ?: ($data['platforms'] ?? $post->targets()->pluck('platform')->all())
        ));
        if (array_key_exists('caption', $data) && $data['caption'] !== null) {
            $data['caption'] = $this->shortenUrlsInText($user, $post, (string) $data['caption']);
        }

        foreach ($data['overrides'] ?? [] as $platform => $override) {
            if (is_string($override) && $override !== '') {
                $data['overrides'][$platform] = $this->shortenUrlsInText($user, $post, $override);
            }
        }

        foreach ($data['targets'] ?? [] as $i => $entry) {
            if (! empty($entry['caption_override'])) {
                $data['targets'][$i]['caption_override'] = $this->shortenUrlsInText($user, $post, $entry['caption_override']);
            }
        }

        foreach ($data['comments'] ?? [] as $i => $comment) {
            if (! empty($comment['body'])) {
                $data['comments'][$i]['body'] = $this->shortenUrlsInText($user, $post, $comment['body']);
            }
        }

        return $data;
    }

    private function uniqueSlug(): string
    {
        do {
            $slug = Str::random(7);
        } while (ShortLink::where('slug', $slug)->exists());

        return $slug;
    }

    private function baseUrl(): string
    {
        return rtrim(config('services.shortlinks.base_url') ?: config('app.url'), '/');
    }
}
