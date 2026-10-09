<?php

namespace App\Support;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;

/**
 * X charges us far more for a post that contains a link ($0.20 vs $0.015 per
 * post on its pay-per-use API), so each X post with a link costs the `x_link`
 * price on top of the post's own price (config/credits.php, `mcp` and `web`).
 *
 * Counted per X account the post goes to, and per X post: the post itself and
 * each comment in its thread are separate X posts. Charged once, when the post
 * is published or scheduled (a draft is charged when it goes out).
 */
class XLinkCharge
{
    /** A web address: with a scheme, www., or a bare domain like example.com (not an email). */
    private const LINK = '~(https?://|www\.)\S+|(?<![@\w.-])[a-z0-9][a-z0-9-]*\.(com|net|org|io|ai|co|app|dev|me|ly|gg|tv|xyz|info|biz|link|page|site|us|uk)\b(/\S*)?~i';

    /**
     * X posts with a link this request sends out: X accounts × (post + comments
     * that contain a link). $data is the create/update payload; $post the saved
     * post on update, null on create.
     */
    public static function count(User $user, array $data, ?Post $post = null): int
    {
        $status = $data['status'] ?? $post?->status ?? Post::STATUS_DRAFT;
        $goesOut = in_array($status, [Post::STATUS_SCHEDULED, Post::STATUS_POSTED], true);
        $alreadyOut = $post !== null && $post->status !== Post::STATUS_DRAFT;

        if (! $goesOut || $alreadyOut) {
            return 0;
        }

        $accounts = self::xAccounts($user, $data, $post);
        if ($accounts === 0) {
            return 0;
        }

        $comments = is_array($data['comments'] ?? null)
            ? array_column($data['comments'], 'body')
            : ($post?->comments->pluck('body')->all() ?? []);

        $texts = [self::xText($data, $post), ...$comments];
        $withLinks = count(array_filter($texts, fn ($text) => is_string($text) && self::hasLink($text)));

        return $accounts * $withLinks;
    }

    public static function hasLink(string $text): bool
    {
        return preg_match(self::LINK, $text) === 1;
    }

    /** The text X gets: its own override if there is one, else the shared caption. */
    private static function xText(array $data, ?Post $post): ?string
    {
        foreach ($data['targets'] ?? [] as $target) {
            if (self::isX($target['platform'] ?? null) && ! empty($target['caption_override'])) {
                return $target['caption_override'];
            }
        }

        $overrides = $data['overrides'] ?? null;
        if (is_array($overrides) && ! empty($overrides['x'])) {
            return is_string($overrides['x']) ? $overrides['x'] : ($overrides['x']['caption'] ?? null);
        }

        $saved = $post?->targets->first(fn ($t) => self::isX($t->platform) && ! empty($t->caption_override));

        return $saved?->caption_override ?? $data['caption'] ?? $post?->caption;
    }

    private static function xAccounts(User $user, array $data, ?Post $post): int
    {
        if (is_array($data['targets'] ?? null)) {
            return count(array_filter($data['targets'], fn ($t) => self::isX($t['platform'] ?? null)));
        }

        if (is_array($data['platforms'] ?? null)) {
            return count(array_filter(array_unique(array_map(fn ($p) => self::isX($p) ? 'x' : $p, $data['platforms'])), fn ($p) => $p === 'x'));
        }

        if (! empty($data['brand_id'])) {
            $brand = $user->brands()->with('socialAccounts')->find((int) $data['brand_id']);

            return $brand?->socialAccounts
                ->where('status', SocialAccount::STATUS_CONNECTED)
                ->filter(fn ($a) => self::isX($a->platform))
                ->count() ?? 0;
        }

        return $post?->targets->filter(fn ($t) => self::isX($t->platform))->count() ?? 0;
    }

    private static function isX(?string $platform): bool
    {
        return in_array(strtolower((string) $platform), ['x', 'twitter'], true);
    }
}
