<?php

namespace App\Services;

use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPostTarget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The user's Instagram media published THROUGH the app, from both posting
 * stores (legacy Post/PostTarget and SocialPost/SocialPostTarget). A tracking
 * link can only pin to media we published ourselves — that's how we know which
 * IG media id to baseline reach against, and which connected account owns it.
 */
class InstagramPublishedPostsService
{
    /** Did this user publish $mediaId through the app? */
    public function existsFor(int $userId, string $mediaId): bool
    {
        return $this->targetFor($userId, $mediaId) !== null;
    }

    /** Caption excerpt for one of the user's published IG media (for content_title). */
    public function textFor(int $userId, string $mediaId): ?string
    {
        $target = $this->targetFor($userId, $mediaId);
        if ($target instanceof PostTarget) {
            return $this->excerpt((string) ($target->post?->caption ?? ''));
        }
        if ($target instanceof SocialPostTarget) {
            return $this->excerpt((string) ($target->post?->content ?? ''));
        }

        return null;
    }

    /** The connected account that published $mediaId — used to read its insights. */
    public function accountFor(int $userId, string $mediaId): ?SocialAccount
    {
        return $this->targetFor($userId, $mediaId)?->socialAccount;
    }

    private function targetFor(int $userId, string $mediaId): ?Model
    {
        $legacy = PostTarget::query()
            ->where('platform', 'instagram')
            ->where('status', PostTarget::STATUS_PUBLISHED)
            ->where('platform_post_id', $mediaId)
            ->whereHas('post', fn ($q) => $q->where('user_id', $userId))
            ->with(['post', 'socialAccount'])
            ->first();
        if ($legacy) {
            return $legacy;
        }

        return SocialPostTarget::query()
            ->where('platform', 'instagram')
            ->where('status', SocialPostTarget::STATUS_PUBLISHED)
            ->where('remote_post_id', $mediaId)
            ->whereHas('post', fn ($q) => $q->where('user_id', $userId))
            ->with(['post', 'socialAccount'])
            ->first();
    }

    private function excerpt(string $caption): string
    {
        return Str::limit(trim($caption), 80);
    }
}
