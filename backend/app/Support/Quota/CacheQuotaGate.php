<?php

namespace App\Support\Quota;

use App\Contracts\Quota\QuotaDecision;
use App\Contracts\Quota\QuotaGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Answers from the day's observed counter (fed by RecordYouTubeQuotaSpend)
 * and the tier's .env threshold. A pure check — nothing is reserved — so it
 * is one cache read and never a database query. If the cache is unreachable
 * it fails open: a monitoring outage must not block users.
 * YOUTUBE_QUOTA_GATE_ENABLED=false turns it into always-yes.
 */
final class CacheQuotaGate implements QuotaGate
{
    public function allows(string $resource, string $tier, int $calls = 1, ?int $userId = null): QuotaDecision
    {
        $resetsAt = Keys::resetsAt();

        if (! config('services.youtube.quota.enabled', true)) {
            return QuotaDecision::allow($resource, $tier, used: 0, cap: null, resetsAt: $resetsAt, reason: 'disabled');
        }

        $cap = Resources::cap($resource, $tier);
        $userCap = $userId === null ? null : Resources::userCap($resource, $tier);

        try {
            $used = (int) Cache::get(Keys::counter($resource), 0);
            $userUsed = $userCap === null ? 0 : (int) Cache::get(Keys::userCounter($userId, $resource), 0);
        } catch (Throwable $e) {
            Log::error('youtube_quota: gate failed open, could not read the counter', [
                'resource' => $resource, 'tier' => $tier, 'error' => $e->getMessage(),
            ]);

            return QuotaDecision::allow($resource, $tier, used: 0, cap: $cap, resetsAt: $resetsAt, reason: 'fail_open');
        }

        if ($cap !== null && $used + $calls > $cap) {
            return QuotaDecision::deny($resource, $tier, $used, $cap, $resetsAt);
        }

        // The allowance counts operations (a search term, an upload, a breakdown),
        // not API calls: one more must fit.
        if ($userCap !== null && $userUsed + 1 > $userCap) {
            return QuotaDecision::denyUser($resource, $tier, $userUsed, $userCap, $resetsAt);
        }

        return QuotaDecision::allow($resource, $tier, $used, $cap, $resetsAt);
    }

    public function recordUserFetch(int $userId, string $resource): void
    {
        try {
            $key = Keys::userCounter($userId, $resource);
            Cache::add($key, 0, Keys::counterTtl());
            Cache::increment($key);
        } catch (Throwable $e) {
            Log::error('youtube_quota: could not count a user fetch', ['user_id' => $userId, 'resource' => $resource, 'error' => $e->getMessage()]);
        }
    }
}
