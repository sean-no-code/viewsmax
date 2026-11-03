<?php

namespace App\Listeners;

use App\Models\YouTubeQuotaSpend;
use App\Support\Quota\Actor;
use App\Support\Quota\Keys;
use App\Support\Quota\QuotaAlerts;
use App\Support\Quota\QuotaLog;
use App\Support\Quota\Resources;
use App\Support\Quota\Source;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sees every HTTP response the app receives and, for YouTube API calls, bumps
 * the day's counter, writes a ledger row and a youtube_quota log line.
 * Observing the client means no call site has to report spend — present and
 * future ones alike. Counted on failure too: Google charges a 403 as a 200.
 * The row's user is whoever the call was for (App\Support\Quota\Actor), its
 * source the unit of work that made it (App\Support\Quota\Source) and its
 * target what it asked for, so a run of rows reads as one search's per-channel
 * fan-out rather than as repeats.
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
        $userId = Actor::id();
        $source = Str::limit((string) Source::id(), 80, '') ?: null;
        $target = $this->target($event->request->url());

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
                'user_id' => $userId,
                'source' => $source,
                'target' => $target,
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
            'user_id' => $userId,
            'source' => $source,
            'target' => $target,
            'used_today' => $used,
        ]);

        if ($used !== null) {
            QuotaAlerts::check($resource, $used, $this->quotaExceeded($event, $status));
        }
    }

    /**
     * What the call asked for, from named query parameters only — the URL also
     * carries the API key, which must never reach the ledger: the uploads
     * playlist, the first video id and how many more rode along, the channel,
     * or the search term; "+page" marks a continuation.
     */
    private function target(string $url): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $param = function (string $key) use ($query): ?string {
            $value = $query[$key] ?? null;

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };

        if ($playlist = $param('playlistId')) {
            $target = 'playlist='.$playlist;
        } elseif ($ids = $param('id')) {
            $list = array_values(array_filter(array_map('trim', explode(',', $ids))));
            $target = 'id='.($list[0] ?? $ids).(count($list) > 1 ? ' +'.(count($list) - 1) : '');
        } elseif ($channel = $param('channelId')) {
            $target = 'channel='.$channel;
        } elseif ($handle = $param('forHandle')) {
            $target = 'handle='.$handle;
        } elseif ($video = $param('videoId')) {
            $target = 'video='.$video;
        } elseif ($q = $param('q')) {
            $target = 'q='.$q;
        } elseif ($param('mine')) {
            $target = 'mine';
        } else {
            return null;
        }

        if ($param('pageToken')) {
            $target .= ' +page';
        }

        return Str::limit($target, 120, '');
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
        } catch (Throwable) {
            return false;
        }
    }
}
