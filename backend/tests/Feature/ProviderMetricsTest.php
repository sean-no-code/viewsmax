<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\Providers\TikTokProvider;
use App\Services\Social\Providers\XProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Audience metrics the providers can pull: TikTok stats are always on (the app
 * is approved for user.info.stats + video.list), and X views come from
 * impression_count on the paid API tier.
 */
class ProviderMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $platform): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => User::factory()->create()->id,
            'platform' => $platform,
            'platform_account_id' => $platform.'-1',
            'username' => 'tester',
            'status' => 'connected',
            'access_token' => 'tok',
            'token_expires_at' => now()->addYear(),
        ]);
    }

    public function test_tiktok_follower_count_and_video_metrics_need_no_flag(): void
    {
        config(['social.platforms.tiktok.client_id' => 'id', 'social.platforms.tiktok.client_secret' => 's']);
        Http::fake([
            'open.tiktokapis.com/v2/user/info/*' => Http::response(['data' => ['user' => ['follower_count' => 1234]]]),
            'open.tiktokapis.com/v2/video/query/*' => Http::response(['data' => ['videos' => [
                ['id' => 'v1', 'like_count' => 10, 'comment_count' => 2, 'share_count' => 3, 'view_count' => 500],
            ]]]),
        ]);

        $provider = app(TikTokProvider::class);
        $account = $this->account('tiktok');

        $this->assertSame(1234, $provider->fetchFollowerCount($account));
        $this->assertSame(['likes' => 10, 'comments' => 2, 'shares' => 3, 'views' => 500], $provider->fetchPostMetrics($account, ['v1'])['v1']);
        $this->assertContains('user.info.stats', config('social.platforms.tiktok.scopes'));
        $this->assertContains('video.list', config('social.platforms.tiktok.scopes'));
    }

    public function test_x_views_come_from_impression_count_and_default_to_zero(): void
    {
        config(['social.platforms.x.client_id' => 'id', 'social.platforms.x.client_secret' => 's']);
        Http::fake([
            'api.twitter.com/2/tweets*' => Http::response(['data' => [
                ['id' => 't1', 'public_metrics' => ['like_count' => 5, 'reply_count' => 1, 'retweet_count' => 2, 'impression_count' => 900]],
                ['id' => 't2', 'public_metrics' => ['like_count' => 0, 'reply_count' => 0, 'retweet_count' => 0]],
            ]]),
        ]);

        $metrics = app(XProvider::class)->fetchPostMetrics($this->account('x'), ['t1', 't2']);

        $this->assertSame(['likes' => 5, 'comments' => 1, 'shares' => 2, 'views' => 900], $metrics['t1']);
        $this->assertSame(0, $metrics['t2']['views']);
    }
}
