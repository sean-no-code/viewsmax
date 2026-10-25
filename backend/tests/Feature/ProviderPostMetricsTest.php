<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\Providers\InstagramProvider;
use App\Services\Social\Providers\TikTokProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Instagram and TikTok hand out expiring preview links, so fetchPostMetrics()
 * returns a fresh `thumbnail` with each day's numbers. Instagram's counts come
 * from two calls — the media node (basic scope: likes, comments, image) and
 * Insights (insights scope: views) — and a post is dropped only when both fail.
 */
class ProviderPostMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $platform): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => User::factory()->create()->id,
            'platform' => $platform,
            'platform_account_id' => '111',
            'name' => ucfirst($platform),
            'access_token' => 'tok',
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    public function test_instagram_combines_the_media_node_with_insights(): void
    {
        config(['social.platforms.instagram.reach_enabled' => true]);
        Http::fake([
            'graph.instagram.com/*/m1/insights*' => Http::response(['data' => [['values' => [['value' => 900]]]]]),
            'graph.instagram.com/*/m1*' => Http::response(['id' => 'm1', 'like_count' => 12, 'comments_count' => 3, 'media_url' => 'https://cdn/m1.mp4', 'thumbnail_url' => 'https://cdn/m1-still.jpg']),
        ]);

        $out = (new InstagramProvider)->fetchPostMetrics($this->account('instagram'), ['m1']);

        Http::assertSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/m1')
            && $r['fields'] === 'like_count,comments_count,media_url,thumbnail_url');
        $this->assertSame(['likes' => 12, 'comments' => 3, 'shares' => 0, 'views' => 900, 'thumbnail' => 'https://cdn/m1-still.jpg'], $out['m1']);
    }

    public function test_instagram_images_use_media_url_and_keep_counts_when_insights_are_refused(): void
    {
        config(['social.platforms.instagram.reach_enabled' => true]);
        Http::fake([
            'graph.instagram.com/*/m2/insights*' => Http::response(['error' => ['message' => 'missing permission']], 400),
            'graph.instagram.com/*/m2*' => Http::response(['id' => 'm2', 'like_count' => 5, 'comments_count' => 1, 'media_url' => 'https://cdn/m2.jpg']),
        ]);

        $out = (new InstagramProvider)->fetchPostMetrics($this->account('instagram'), ['m2']);

        $this->assertSame(['likes' => 5, 'comments' => 1, 'shares' => 0, 'views' => 0, 'thumbnail' => 'https://cdn/m2.jpg'], $out['m2']);
    }

    public function test_instagram_drops_a_post_when_both_lookups_fail(): void
    {
        config(['social.platforms.instagram.reach_enabled' => true]);
        Http::fake(['graph.instagram.com/*' => Http::response(['error' => ['message' => 'nope']], 400)]);

        $this->assertSame([], (new InstagramProvider)->fetchPostMetrics($this->account('instagram'), ['m3']));
    }

    public function test_tiktok_metrics_carry_the_cover_image(): void
    {
        config(['social.platforms.tiktok.stats_enabled' => true]);
        Http::fake([
            'open.tiktokapis.com/v2/video/query/*' => Http::response(['data' => ['videos' => [
                ['id' => '71', 'like_count' => 4, 'comment_count' => 1, 'share_count' => 2, 'view_count' => 300, 'cover_image_url' => 'https://tt-cdn/71.jpg'],
            ]]]),
        ]);

        $out = (new TikTokProvider)->fetchPostMetrics($this->account('tiktok'), ['71']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'cover_image_url'));
        $this->assertSame(['likes' => 4, 'comments' => 1, 'shares' => 2, 'views' => 300, 'thumbnail' => 'https://tt-cdn/71.jpg'], $out['71']);
    }
}
