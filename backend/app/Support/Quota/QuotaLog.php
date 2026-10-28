<?php

namespace App\Support\Quota;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The youtube_quota log (storage/logs/youtube-quota.log): one line per outlier
 * search saying where it was served from, and one per YouTube API call saying
 * what it cost. YOUTUBE_QUOTA_LOG_ENABLED=false silences the file.
 *
 *   youtube_quota.search  term="ai agents" served=cache   reason="done, fetched 3d ago (period 30d)"
 *   youtube_quota.search  term="ai agents" served=youtube calls=2 stage=request
 *   youtube_quota.search  term="ai agents" served=paused  used=48 cap=48 resets_at=…
 *   youtube_quota.spend   search_list 100u GET /youtube/v3/search 200 used_today=13
 */
final class QuotaLog
{
    public const CHANNEL = 'youtube_quota';

    public const SERVED_CACHE = 'cache';

    public const SERVED_YOUTUBE = 'youtube';

    public const SERVED_PAUSED = 'paused';

    /** A search term's fate: answered from what we already hold, fetched from YouTube, or paused for quota. */
    public static function search(string $served, string $term, ?User $user, array $context = []): void
    {
        Log::channel(self::CHANNEL)->info('youtube_quota.search', [
            'term' => $term,
            'served' => $served,
            'user_id' => $user?->id,
            'tier' => Tier::of($user),
        ] + $context);
    }

    /** One YouTube API call and what it cost. */
    public static function spend(array $context): void
    {
        Log::channel(self::CHANNEL)->info('youtube_quota.spend', $context);
    }
}
