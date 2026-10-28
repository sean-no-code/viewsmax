<?php

namespace Tests\Feature;

use App\Jobs\ImportChannelVideosJob;
use App\Jobs\ImportRecentPostsJob;
use App\Models\Channel;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Analytics is the Pro tier upsell (App\Support\AnalyticsAccess): connecting a
 * channel on free/starter/creator must spend nothing — no uploads import, no
 * recent-posts import, no channel analytics fetch — while pro/agency, admins
 * and promotional customers are unchanged.
 */
class AnalyticsPlanGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret']);
    }

    private function fakeGoogle(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-at', 'refresh_token' => 'new-rt', 'expires_in' => 3600,
                'token_type' => 'Bearer', 'scope' => 'https://www.googleapis.com/auth/youtube.readonly',
            ]),
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [[
                'id' => 'UCchan1',
                'snippet' => ['title' => 'My Channel', 'description' => '', 'customUrl' => '@me', 'publishedAt' => '2020-01-01T00:00:00Z', 'thumbnails' => ['default' => ['url' => 'https://img/d.png']]],
                'statistics' => ['subscriberCount' => '10', 'videoCount' => '1', 'viewCount' => '100'],
            ]]]),
            '*' => Http::response([], 200),
        ]);
    }

    private function exchange(User $user)
    {
        $token = $user->createToken('t')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->postJson('/api/auth/youtube/exchange', ['code' => 'c', 'redirect_uri' => 'https://app.test/oauth/callback']);
    }

    /** @return array<string, array{0: string}> */
    public static function belowProPlans(): array
    {
        return ['free' => ['free'], 'starter' => ['starter'], 'creator' => ['creator']];
    }

    /** @return array<string, array{0: string}> */
    public static function analyticsPlans(): array
    {
        return ['pro' => ['pro'], 'agency' => ['agency'], 'legacy creator_agency' => ['creator_agency']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('belowProPlans')]
    public function test_connecting_youtube_below_pro_imports_nothing(string $planName): void
    {
        Queue::fake();
        $this->fakeGoogle();
        $user = User::factory()->withPlan('active', $this->planNamed($planName))->create();

        $this->exchange($user)->assertOk()->assertJsonPath('data.channel_connected', true);

        // The channel itself is still connected — only analytics is withheld.
        $channel = Channel::where('user_id', $user->id)->sole();
        $this->assertSame('UCchan1', $channel->youtube_channel_id);
        $this->assertNull($channel->views_over_time);
        $this->assertNull($channel->analytics_last_updated);
        $this->assertSame(1, SocialAccount::where('user_id', $user->id)->count());

        Queue::assertNotPushed(ImportChannelVideosJob::class);
        Queue::assertNotPushed(ImportRecentPostsJob::class);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'youtubeanalytics.googleapis.com')
            || str_contains($request->url(), '/youtube/v3/playlists'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('analyticsPlans')]
    public function test_connecting_youtube_on_an_analytics_plan_queues_the_imports(string $planName): void
    {
        Queue::fake();
        $this->fakeGoogle();
        $user = User::factory()->withPlan('active', $this->planNamed($planName))->create();

        $this->exchange($user)->assertOk();

        $channel = Channel::where('user_id', $user->id)->sole();
        $account = SocialAccount::where('user_id', $user->id)->sole();
        Queue::assertPushed(ImportChannelVideosJob::class, fn ($job) => $job->channelId === $channel->id);
        Queue::assertPushed(ImportRecentPostsJob::class, fn ($job) => $job->socialAccountId === $account->id);
        $this->assertNotNull($channel->analytics_last_updated);
    }

    public function test_an_admin_keeps_the_imports(): void
    {
        $this->assertImportsQueuedFor(User::factory()->admin()->create());
    }

    public function test_a_promotional_customer_keeps_the_imports(): void
    {
        $this->assertImportsQueuedFor(User::factory()->promotional()->create());
    }

    /** Both exempt roles connect exactly as a Pro user does. */
    private function assertImportsQueuedFor(User $user): void
    {
        Queue::fake();
        $this->fakeGoogle();

        $this->exchange($user)->assertOk();

        Queue::assertPushed(ImportChannelVideosJob::class);
        Queue::assertPushed(ImportRecentPostsJob::class);
    }

    public function test_the_video_import_job_spends_nothing_below_pro(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $user = User::factory()->withPlan('active', $this->planNamed('creator'))->create();
        $channel = Channel::create([
            'user_id' => $user->id,
            'youtube_channel_id' => 'UC'.Str::random(8),
            'channel_name' => 'My Channel',
            'youtube_access_token' => 'yt-oauth-token',
        ]);

        ImportChannelVideosJob::dispatchSync($channel->id);

        $this->assertSame(0, Video::where('channel_id', $channel->id)->count());
        Http::assertNothingSent();
    }

    public function test_the_recent_posts_job_spends_nothing_below_pro(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $user = User::factory()->withPlan('active', $this->planNamed('creator'))->create();
        $account = SocialAccount::create([
            'user_id' => $user->id, 'platform' => 'youtube', 'platform_account_id' => 'UCchan1',
            'name' => 'My Channel', 'access_token' => 'tok', 'token_expires_at' => now()->addHour(),
            'status' => SocialAccount::STATUS_CONNECTED, 'metadata' => ['channel_id' => 'UCchan1'],
        ]);

        ImportRecentPostsJob::dispatchSync($account->id);

        $this->assertDatabaseCount('post_metric_snapshots', 0);
        Http::assertNothingSent();
    }

    public function test_channel_refresh_is_a_pro_feature_but_fetching_videos_is_not(): void
    {
        $creator = User::factory()->withPlan('active', $this->planNamed('creator'))->create();
        $channel = Channel::create([
            'user_id' => $creator->id,
            'youtube_channel_id' => 'UC'.Str::random(8),
            'channel_name' => 'My Channel',
            'youtube_access_token' => 'yt-oauth-token',
            'youtube_token_expires_at' => now()->addHour(),
        ]);

        $headers = ['Authorization' => 'Bearer '.$creator->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        $this->withHeaders($headers)
            ->postJson("/api/channels/{$channel->id}/refresh")
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        // The offer/Review video picker must keep working on every tier: its
        // import is an explicit user action, not analytics.
        Http::fake([
            'www.googleapis.com/youtube/v3/playlistItems*' => Http::response(['items' => []]),
            '*' => Http::response([], 200),
        ]);

        $this->withHeaders($headers)
            ->postJson("/api/channels/{$channel->id}/fetch-videos")
            ->assertOk();
    }
}
