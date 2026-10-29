<?php

namespace Tests\Feature;

use App\Jobs\ImportChannelVideosJob;
use App\Models\Channel;
use App\Models\User;
use App\Models\YouTubeQuotaSpend;
use App\Services\YouTubeChannelService;
use App\Support\Quota\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every ledger row should say whose quota the call spent. Most YouTube calls
 * happen where nobody is signed in (queue workers, nightly commands), so those
 * paths name the user through App\Support\Quota\Actor and the spend listener
 * reads it back; a job queued by a signed-in request inherits the requester.
 */
class YouTubeQuotaAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
    }

    private function youtubeCall(): void
    {
        Http::get('https://www.googleapis.com/youtube/v3/videos?id=v');
    }

    private function channel(User $owner): Channel
    {
        return Channel::create([
            'user_id' => $owner->id,
            'youtube_channel_id' => 'chan-'.Str::random(6),
            'channel_name' => 'My Channel',
            'youtube_access_token' => 'yt-oauth-token',
        ]);
    }

    /** The mocked import makes one YouTube call, as the real one would. */
    private function importerThatCallsYouTube(int $times): void
    {
        $this->mock(YouTubeChannelService::class, function ($m) use ($times) {
            $m->shouldReceive('fetchAndImportChannelVideos')->times($times)->andReturnUsing(function () {
                Http::get('https://www.googleapis.com/youtube/v3/playlistItems?playlistId=UU1');

                return ['imported' => 0, 'updated' => 0, 'total' => 0];
            });
        });
    }

    public function test_an_explicit_actor_is_attributed_when_nobody_is_signed_in(): void
    {
        $owner = User::factory()->create();

        Actor::set($owner);
        $this->youtubeCall();

        $this->assertSame($owner->id, YouTubeQuotaSpend::sole()->user_id);
    }

    public function test_the_explicit_actor_wins_over_the_signed_in_user(): void
    {
        $owner = User::factory()->create();
        $this->actingAs(User::factory()->create());

        Actor::set($owner->id);
        $this->youtubeCall();

        $this->assertSame($owner->id, YouTubeQuotaSpend::sole()->user_id);
    }

    public function test_clearing_the_actor_leaves_the_call_unattributed(): void
    {
        Actor::set(User::factory()->create());
        Actor::set(null);

        $this->youtubeCall();

        $this->assertNull(YouTubeQuotaSpend::sole()->user_id);
    }

    public function test_a_job_queued_by_a_signed_in_request_inherits_the_requester(): void
    {
        $requester = User::factory()->create();
        $this->actingAs($requester);

        // What the queue stores alongside the job payload at dispatch time…
        $payload = Context::dehydrate();

        // …and a worker process: nobody signed in, context rebuilt from that payload.
        $this->app['auth']->forgetGuards();
        Context::flush();
        $this->assertNull(Actor::id());

        Context::hydrate($payload);
        $this->youtubeCall();

        $this->assertSame($requester->id, YouTubeQuotaSpend::sole()->user_id);
    }

    public function test_channels_sync_videos_attributes_each_channel_to_its_owner(): void
    {
        $a = $this->analyticsUser();
        $b = $this->analyticsUser();
        $this->channel($a);
        $this->channel($b);
        $this->importerThatCallsYouTube(2);

        $this->artisan('channels:sync-videos')->assertExitCode(0);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], YouTubeQuotaSpend::pluck('user_id')->all());
    }

    public function test_import_channel_videos_job_attributes_to_the_channel_owner(): void
    {
        $owner = $this->analyticsUser();
        $channel = $this->channel($owner);
        $this->importerThatCallsYouTube(1);

        (new ImportChannelVideosJob($channel->id))->handle(app(YouTubeChannelService::class));

        $this->assertSame($owner->id, YouTubeQuotaSpend::sole()->user_id);
    }
}
