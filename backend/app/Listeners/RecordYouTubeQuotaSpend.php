<?php

namespace App\Listeners;

use App\Models\YouTubeQuotaSpend;
use App\Support\Quota\Actor;
use App\Support\Quota\Keys;
use App\Support\Quota\QuotaAlerts;
use App\Support\Quota\QuotaLog;
use App\Support\Quota\Resources;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sees every HTTP response the app receives and, for YouTube API calls, bumps
 * the day's counter, writes a ledger row and a youtube_quota log line.
 * Observing the client means no call site has to report spend — present and
 * future ones alike. Counted on failure too: Google charges a 403 as a 200.
 * The row's user is whoever the call was for (App\Support\Quota\Actor).
 * Auto-discovered (app/Listeners).
 */
final class RecordYouTubeQuotaSpend
{
    public function handle(ResponseReceived $event): void
    {
        $match = Resources::match($event->request->method(), $event->request->url());
        if ($match === null) {
            return;
        }

        $resource = $match['resource'];
        $units = Resources::units($resource);
        $status = $event->response->status();
        $method = strtoupper($event->request->method());

        try {
            $key = Keys::counter($resource);
            Cache::add($key, 0, Keys::counterTtl());
            $used = Cache::increment($key);

            YouTubeQuotaSpend::create([
                'resource' => $resource,
                'endpoint' => $match['endpoint'],
                'method' => $method,
                'status' => $status,
                'units' => $units,
                'user_id' => Actor::id(),
            ]);
        } catch (Throwable $e) {
            // Never let bookkeeping break the call that just succeeded.
            Log::error('youtube_quota: could not record spend', ['resource' => $resource, 'error' => $e->getMessage()]);
            $used = null;
        }

        QuotaLog::spend([
            'resource' => $resource,
            'units' => $units,
            'method' => $method,
            'endpoint' => $match['endpoint'],
            'status' => $status,
            'user_id' => Actor::id(),
            'used_today' => $used,
        ]);

        if ($used !== null) {
            QuotaAlerts::check($resource, $used, $this->quotaExceeded($event, $status));
        }
    }

    /** Google's own "no more today": a 403 whose error reason is quotaExceeded. */
    private function quotaExceeded(ResponseReceived $event, int $status): bool
    {
        if ($status !== 403) {
            return false;
        }

        try {
            $reasons = collect($event->response->json('error.errors') ?? [])->pluck('reason')->all();

            return in_array('quotaExceeded', $reasons, true) || $event->response->json('error.status') === 'RESOURCE_EXHAUSTED';
        } catch (\Throwable) {
            return false;
        }
    }
}
