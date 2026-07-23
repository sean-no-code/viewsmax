<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\OAuthConnectionService;
use App\Services\Social\Providers\InstagramProvider;
use App\Services\TikTokPublishService;
use App\Services\YouTubePublishService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class PostThumbnailTest extends TestCase
{
    public function test_youtube_set_thumbnail_uploads_image_to_thumbnails_endpoint(): void
    {
        Http::fake(['*thumbnails/set*' => Http::response(['kind' => 'youtube#thumbnailSetResponse'], 200)]);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'fake-image-bytes');
        rewind($stream);

        (new YouTubePublishService)->setThumbnail('token', 'vid123', $stream, 'image/jpeg');
        if (is_resource($stream)) {
            fclose($stream);
        }

        Http::assertSent(fn ($req) => str_contains($req->url(), 'thumbnails/set')
            && str_contains($req->url(), 'videoId=vid123'));
    }

    /** A TikTok SocialAccount with a live token (ensureFreshToken is a no-op). */
    private function tiktokAccount(): SocialAccount
    {
        return new SocialAccount(['platform' => 'tiktok', 'access_token' => 'token']);
    }

    public function test_tiktok_direct_post_includes_cover_timestamp_when_set(): void
    {
        Http::fake(['*/post/publish/video/init/' => Http::response(['data' => ['publish_id' => 'pub-1']], 200)]);

        $id = app(TikTokPublishService::class)->initDirectPost($this->tiktokAccount(), 'caption', 'https://cdn.example/clip.mp4', [
            'privacy_level' => 'SELF_ONLY',
            'video_cover_timestamp_ms' => 3000,
        ]);

        $this->assertSame('pub-1', $id);
        Http::assertSent(fn ($req) => ($req->data()['post_info']['video_cover_timestamp_ms'] ?? null) === 3000);
    }

    public function test_tiktok_direct_post_omits_cover_timestamp_when_absent(): void
    {
        Http::fake(['*/post/publish/video/init/' => Http::response(['data' => ['publish_id' => 'pub-2']], 200)]);

        app(TikTokPublishService::class)->initDirectPost($this->tiktokAccount(), 'caption', 'https://cdn.example/clip.mp4', [
            'privacy_level' => 'SELF_ONLY',
        ]);

        Http::assertSent(fn ($req) => ! array_key_exists('video_cover_timestamp_ms', $req->data()['post_info']));
    }

    public function test_instagram_reel_sends_cover_url_when_set(): void
    {
        Http::fake([
            '*media_publish*' => Http::response(['id' => 'media-1'], 200),
            '*/media' => Http::response(['id' => 'container-1'], 200),
            '*' => Http::response(['status_code' => 'FINISHED'], 200),
        ]);

        $account = new SocialAccount([
            'platform' => 'instagram',
            'platform_account_id' => 'ig-1',
            'access_token' => 'token',
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        $post = new SocialPost([
            'content' => 'A reel',
            'media' => [['type' => 'video', 'url' => 'https://cdn.example/clip.mp4']],
        ]);
        $post->cover_url = 'https://cdn.example/cover.jpg';

        $result = (new InstagramProvider)->publish($account, $post);

        $this->assertTrue($result->success, (string) $result->error);
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/media')
            && ($req->data()['cover_url'] ?? null) === 'https://cdn.example/cover.jpg');
    }
}
