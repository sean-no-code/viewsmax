<?php

namespace Tests\Feature;

use App\Models\YouTubeQuotaSpend;
use App\Support\Quota\Keys;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every YouTube API call the app makes is observed from the HTTP client's
 * ResponseReceived event, so nothing in the app has to remember to report
 * spend: the counter the gate reads and the ledger the dashboard shows are
 * both fed from here.
 */
class YouTubeQuotaSpendRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Everything answers 200 unless a test fakes otherwise first (stubs
        // match in registration order, so the 403 test registers its own).
        if (! str_contains($this->name(), 'failed_call')) {
            Http::fake(['*' => Http::response(['ok' => true], 200)]);
        }
    }

    public function test_a_search_list_call_bumps_the_counter_and_writes_the_ledger(): void
    {
        Http::get('https://www.googleapis.com/youtube/v3/search', ['q' => 'ai', 'key' => 'k']);

        $this->assertSame(1, Cache::get(Keys::counter('search_list')));
        $spend = YouTubeQuotaSpend::sole();
        $this->assertSame('search_list', $spend->resource);
        $this->assertSame(100, $spend->units);
        $this->assertSame('GET', $spend->method);
        $this->assertSame(200, $spend->status);
        $this->assertSame('/youtube/v3/search', $spend->endpoint);
        $this->assertSame(Keys::date(), $spend->pt_date);
        $this->assertNull($spend->user_id);
    }

    /** @dataProvider endpoints */
    public function test_each_endpoint_maps_to_its_resource_and_unit_cost(string $method, string $url, string $resource, int $units): void
    {
        Http::send($method, $url);

        $spend = YouTubeQuotaSpend::sole();
        $this->assertSame($resource, $spend->resource);
        $this->assertSame($units, $spend->units);
        $this->assertSame(1, Cache::get(Keys::counter($resource)));
    }

    public static function endpoints(): array
    {
        return [
            'videos.insert' => ['POST', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', 'videos_insert', 1600],
            'thumbnails.set' => ['POST', 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId=v&uploadType=media', 'thumbnails_set', 50],
            'videos.list' => ['GET', 'https://www.googleapis.com/youtube/v3/videos?id=v', 'videos_list', 1],
            'channels.list' => ['GET', 'https://www.googleapis.com/youtube/v3/channels?mine=true', 'channels_list', 1],
            'playlistItems.list' => ['GET', 'https://www.googleapis.com/youtube/v3/playlistItems?playlistId=UU1', 'playlist_items_list', 1],
            'playlists.list' => ['GET', 'https://www.googleapis.com/youtube/v3/playlists?channelId=UC1', 'playlists_list', 1],
            'anything else under youtube/v3' => ['GET', 'https://www.googleapis.com/youtube/v3/captions?videoId=v', 'other', 1],
            'analytics (separate quota, tracked for visibility)' => ['GET', 'https://youtubeanalytics.googleapis.com/v2/reports?ids=channel==x', 'analytics_reports', 0],
        ];
    }

    public function test_resumable_upload_chunks_are_not_counted_as_inserts(): void
    {
        // Only the POST that opens the session is videos.insert (1600 units);
        // the PUTs that stream bytes and probe the session cost nothing.
        Http::post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', ['snippet' => []]);
        Http::put('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=abc');
        Http::put('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=abc');

        $this->assertSame(1, Cache::get(Keys::counter('videos_insert')));
        $this->assertSame(1, YouTubeQuotaSpend::count());
    }

    public function test_calls_to_other_hosts_are_ignored(): void
    {
        Http::get('https://api.example.com/youtube/v3/search');
        Http::get('https://oauth2.googleapis.com/token');

        $this->assertSame(0, YouTubeQuotaSpend::count());
        $this->assertNull(Cache::get(Keys::counter('search_list')));
    }

    public function test_a_failed_call_still_counts_because_google_still_charges_it(): void
    {
        Http::fake(['*' => Http::response(['error' => 'quotaExceeded'], 403)]);

        Http::get('https://www.googleapis.com/youtube/v3/search?q=x');

        $this->assertSame(1, Cache::get(Keys::counter('search_list')));
        $this->assertSame(403, YouTubeQuotaSpend::sole()->status);
    }

    public function test_the_signed_in_user_is_attributed_when_there_is_one(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Http::get('https://www.googleapis.com/youtube/v3/videos?id=v');

        $this->assertSame($user->id, YouTubeQuotaSpend::sole()->user_id);
    }

    public function test_the_counter_accumulates_across_calls(): void
    {
        Http::get('https://www.googleapis.com/youtube/v3/search?q=a');
        Http::get('https://www.googleapis.com/youtube/v3/search?q=b');
        Http::get('https://www.googleapis.com/youtube/v3/videos?id=v');

        $this->assertSame(2, Cache::get(Keys::counter('search_list')));
        $this->assertSame(1, Cache::get(Keys::counter('videos_list')));
    }
}
