<?php

namespace App\Services;

use App\Models\PostTarget;
use App\Models\SocialPostTarget;
use App\Services\Social\CaptionRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The user's X posts published THROUGH the app, merged from both posting
 * stores (legacy Post/PostTarget and SocialPost/SocialPostTarget). This is a
 * pure DB read — the X API plan doesn't allow timeline reads, so tracking
 * links can only pin to posts we published ourselves.
 */
class XPublishedPostsService
{
    /**
     * @return array<int, array{id:string, text:string, url:string, posted_at:?string}>
     */
    public function listFor(int $userId, int $limit = 50): array
    {
        $legacy = PostTarget::query()
            ->where('platform', 'x')
            ->where('status', PostTarget::STATUS_PUBLISHED)
            ->whereNotNull('platform_post_id')
            ->whereHas('post', fn ($q) => $q->where('user_id', $userId))
            ->with('post')
            ->get()
            ->map(fn (PostTarget $t) => [
                'id' => (string) $t->platform_post_id,
                'text' => $this->excerpt((string) ($t->post?->caption ?? '')),
                'url' => data_get($t->meta, 'url') ?: 'https://x.com/i/web/status/'.$t->platform_post_id,
                'posted_at' => $this->iso($t->published_at),
            ]);

        $social = SocialPostTarget::query()
            ->where('platform', 'x')
            ->where('status', SocialPostTarget::STATUS_PUBLISHED)
            ->whereNotNull('remote_post_id')
            ->whereHas('post', fn ($q) => $q->where('user_id', $userId))
            ->with('post')
            ->get()
            ->map(fn (SocialPostTarget $t) => [
                'id' => (string) $t->remote_post_id,
                'text' => $this->excerpt((string) ($t->post?->content ?? '')),
                'url' => $t->remote_post_url ?: 'https://x.com/i/web/status/'.$t->remote_post_id,
                'posted_at' => $this->iso($t->published_at),
            ]);

        return $legacy->concat($social)
            ->unique('id')
            ->sortByDesc('posted_at')
            ->take($limit)
            ->values()
            ->all();
    }

    /** Display text (first-tweet excerpt) for one of the user's published posts. */
    public function textFor(int $userId, string $xPostId): ?string
    {
        $legacy = PostTarget::query()
            ->where('platform', 'x')
            ->where('status', PostTarget::STATUS_PUBLISHED)
            ->where('platform_post_id', $xPostId)
            ->whereHas('post', fn ($q) => $q->where('user_id', $userId))
            ->with('post')
            ->first();
        if ($legacy) {
            return $this->excerpt((string) ($legacy->post?->caption ?? ''));
        }

        $social = SocialPostTarget::query()
            ->where('platform', 'x')
            ->where('status', SocialPostTarget::STATUS_PUBLISHED)
            ->where('remote_post_id', $xPostId)
            ->whereHas('post', fn ($q) => $q->where('user_id', $userId))
            ->with('post')
            ->first();

        return $social ? $this->excerpt((string) ($social->post?->content ?? '')) : null;
    }

    public function existsFor(int $userId, string $xPostId): bool
    {
        return $this->textFor($userId, $xPostId) !== null;
    }

    /** A thread caption shows its first tweet's text, capped for index display. */
    private function excerpt(string $caption): string
    {
        $first = CaptionRules::splitXThread($caption)[0] ?? '';

        return Str::limit($first, 80);
    }

    private function iso($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }
}
