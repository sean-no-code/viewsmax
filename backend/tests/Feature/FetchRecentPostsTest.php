<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\Providers\InstagramProvider;
use App\Services\Social\Providers\TikTokProvider;
use App\Services\Social\Providers\XProvider;
use App\Services\Social\Providers\YouTubeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * fetchRecentPosts() lists an account's latest posts on the platform
 * (ids, url, caption, publish date — no metrics) for the on-connect import.
 * Each provider uses the cheapest listing its API offers and degrades to an
 * empty list on failure rather than throwing.
 */
class FetchRecentPostsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $platform, array $attrs = []): SocialAccount
    {
        return SocialAccount::create($attrs + [
            'user_id' => User::factory()->create()->id,
            'platform' => $platform,
            'platform_account_id' => '111',
            'name' => ucfirst($platform),
            'access_token' => 'tok',
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    public function test_youtube_lists_the_public_uploads_only(): void
    {
        Http::fake([
            'www.googleapis.com/youtube/v3/playlistItems*' => Http::response(['items' => [
                ['snippet' => ['title' => 'Draft', 'publishedAt' => '2026-10-03T09:00:00Z'], 'contentDetails' => ['videoId' => 'v3'], 'status' => ['privacyStatus' => 'private']],
                ['snippet' => ['title' => 'Newest', 'publishedAt' => '2026-10-02T09:00:00Z'], 'contentDetails' => ['videoId' => 'v2', 'videoPublishedAt' => '2026-10-02T10:00:00Z'], 'status' => ['privacyStatus' => 'public']],
                ['snippet' => ['title' => 'Hidden', 'publishedAt' => '2026-09-15T09:00:00Z'], 'contentDetails' => ['videoId' => 'v15'], 'status' => ['privacyStatus' => 'unlisted']],
                ['snippet' => ['title' => 'Older', 'publishedAt' => '2026-09-01T09:00:00Z'], 'contentDetails' => ['videoId' => 'v1'], 'status' => ['privacyStatus' => 'public']],
            ]]),
        ]);
        $account = $this->account('youtube', ['platform_account_id' => 'UCabc', 'metadata' => ['channel_id' => 'UCabc']]);

        $posts = (new YouTubeProvider)->fetchRecentPosts($account, 10);

        // One full page (private/unlisted get filtered out, so more than $limit is asked for).
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/youtube/v3/playlistItems')
            && $r['playlistId'] === 'UUabc' && (int) $r['maxResults'] === 50
            && str_contains($r['part'], 'status')
            && $r->hasHeader('Authorization', 'Bearer tok'));
        $this->assertSame(['v2', 'v1'], array_column($posts, 'remote_post_id'));
        $this->assertSame('https://www.youtube.com/watch?v=v2', $posts[0]['url']);
        $this->assertSame('Newest', $posts[0]['caption']);
        $this->assertSame('2026-10-02T10:00:00+00:00', $posts[0]['published_at']->toIso8601String());
        $this->assertSame('2026-09-01T09:00:00+00:00', $posts[1]['published_at']->toIso8601String()); // falls back to snippet
    }

    public function test_youtube_treats_a_missing_uploads_playlist_as_no_posts(): void
    {
        Http::fake(['www.googleapis.com/youtube/v3/playlistItems*' => Http::response(['error' => ['code' => 404]], 404)]);
        $account = $this->account('youtube', ['platform_account_id' => 'UCnew']);

        $this->assertSame([], (new YouTubeProvider)->fetchRecentPosts($account, 10));
    }

    public function test_x_lists_original_tweets_only(): void
    {
        Http::fake([
            'api.twitter.com/2/users/111/tweets*' => Http::response(['data' => [
                ['id' => '900', 'text' => 'hello', 'created_at' => '2026-10-03T12:00:00.000Z'],
                ['id' => '800', 'text' => 'earlier'],
            ]]),
        ]);
        $account = $this->account('x', ['username' => 'jo']);

        $posts = (new XProvider)->fetchRecentPosts($account, 10);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/2/users/111/tweets')
            && $r['exclude'] === 'retweets,replies' && (int) $r['max_results'] === 10);
        $this->assertSame(['900', '800'], array_column($posts, 'remote_post_id'));
        $this->assertSame('https://x.com/jo/status/900', $posts[0]['url']);
        $this->assertSame('hello', $posts[0]['caption']);
        $this->assertSame('2026-10-03T12:00:00+00:00', $posts[0]['published_at']->toIso8601String());
        $this->assertNull($posts[1]['published_at']);
    }

    public function test_x_returns_nothing_when_the_lookup_fails(): void
    {
        Http::fake(['api.twitter.com/2/users/111/tweets*' => Http::response(['title' => 'Forbidden'], 403)]);

        $this->assertSame([], (new XProvider)->fetchRecentPosts($this->account('x'), 10));
    }

    public function test_tiktok_lists_videos_when_stats_are_enabled(): void
    {
        config(['social.platforms.tiktok.stats_enabled' => true]);
        Http::fake([
            'open.tiktokapis.com/v2/video/list/*' => Http::response(['data' => ['videos' => [
                ['id' => '71', 'title' => 'Titled', 'video_description' => 'desc', 'create_time' => 1759400000, 'share_url' => 'https://tt/71'],
                ['id' => '70', 'title' => '', 'video_description' => 'Only a description', 'create_time' => 1759300000, 'share_url' => 'https://tt/70'],
            ]]]),
        ]);

        $posts = (new TikTokProvider)->fetchRecentPosts($this->account('tiktok'), 10);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v2/video/list/')
            && str_contains($r->url(), 'fields=id,title,video_description,create_time,share_url')
            && $r['max_count'] === 10);
        $this->assertSame(['71', '70'], array_column($posts, 'remote_post_id'));
        $this->assertSame('Titled', $posts[0]['caption']);
        $this->assertSame('Only a description', $posts[1]['caption']);
        $this->assertSame('https://tt/71', $posts[0]['url']);
        $this->assertSame(1759400000, $posts[0]['published_at']->getTimestamp());
    }

    public function test_tiktok_lists_nothing_while_stats_are_disabled(): void
    {
        config(['social.platforms.tiktok.stats_enabled' => false]);
        Http::fake();

        $this->assertSame([], (new TikTokProvider)->fetchRecentPosts($this->account('tiktok'), 10));
        Http::assertNothingSent();
    }

    public function test_instagram_lists_the_accounts_media(): void
    {
        Http::fake([
            'graph.instagram.com/*/me/media*' => Http::response(['data' => [
                ['id' => '55', 'caption' => 'Reel caption', 'permalink' => 'https://www.instagram.com/reel/abc/', 'timestamp' => '2026-10-04T08:00:00+0000'],
                ['id' => '54', 'permalink' => 'https://www.instagram.com/p/def/', 'timestamp' => '2026-10-01T08:00:00+0000'],
            ]]),
        ]);

        $posts = (new InstagramProvider)->fetchRecentPosts($this->account('instagram'), 10);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/me/media')
            && $r['fields'] === 'id,caption,permalink,timestamp' && (int) $r['limit'] === 10
            && $r['access_token'] === 'tok');
        $this->assertSame(['55', '54'], array_column($posts, 'remote_post_id'));
        $this->assertSame('Reel caption', $posts[0]['caption']);
        $this->assertSame('', $posts[1]['caption']);
        $this->assertSame('https://www.instagram.com/reel/abc/', $posts[0]['url']);
        $this->assertSame('2026-10-04T08:00:00+00:00', $posts[0]['published_at']->toIso8601String());
    }

    public function test_the_list_is_capped_at_the_requested_limit(): void
    {
        Http::fake([
            'api.twitter.com/2/users/111/tweets*' => Http::response(['data' => array_map(
                fn ($i) => ['id' => (string) $i, 'text' => "t{$i}"], range(1, 7)
            )]),
        ]);

        // X's minimum page size is 5, so ask for 5 and trim to the 3 wanted.
        $posts = (new XProvider)->fetchRecentPosts($this->account('x'), 3);

        Http::assertSent(fn (Request $r) => (int) $r['max_results'] === 5);
        $this->assertCount(3, $posts);
    }
}
