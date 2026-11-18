<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Publish-time media rules in PostController (App\Services\Social\MediaRules).
 * Drafts stay unrestricted; scheduling or posting media a platform can't take
 * (missing, too many, unsupported video, images mixed with video) is a 422
 * naming the platform, so the failure surfaces now rather than in the job.
 */
class PostMediaRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    private function createPost(array $payload)
    {
        return $this->withHeaders($this->auth())->postJson('/api/posts', $payload);
    }

    private function images(int $n): array
    {
        return array_map(fn ($i) => ['type' => 'image', 'url' => "https://cdn.example/{$i}.jpg"], range(1, $n));
    }

    private function video(): array
    {
        return ['type' => 'video', 'url' => 'https://cdn.example/clip.mp4', 'path' => 'posts/1/clip.mp4'];
    }

    private function scheduled(array $payload): array
    {
        return $payload + ['status' => 'scheduled', 'scheduled_at' => now()->addHour()->toDateTimeString()];
    }

    // ── Instagram ─────────────────────────────────────────────────────────

    public function test_instagram_without_media_is_rejected_at_schedule_time(): void
    {
        $response = $this->createPost($this->scheduled(['caption' => 'hi', 'platforms' => ['instagram'], 'media' => []]));

        $response->assertStatus(422);
        $this->assertStringContainsString('Instagram', $response->json('message'));
        $this->assertSame(0, Post::count());
    }

    public function test_instagram_draft_without_media_is_allowed(): void
    {
        $this->createPost(['caption' => 'hi', 'platforms' => ['instagram'], 'status' => 'draft'])->assertStatus(201);
    }

    public function test_instagram_allows_up_to_ten_media_items(): void
    {
        $this->createPost($this->scheduled(['caption' => 'hi', 'platforms' => ['instagram'], 'media' => $this->images(10)]))
            ->assertStatus(201);

        $response = $this->createPost($this->scheduled(['caption' => 'hi', 'platforms' => ['instagram'], 'media' => $this->images(11)]));
        $response->assertStatus(422);
        $this->assertStringContainsString('Instagram', $response->json('message'));
        $this->assertStringContainsString('10', $response->json('message'));
    }

    // ── X ─────────────────────────────────────────────────────────────────

    public function test_x_allows_four_images_but_not_five(): void
    {
        $this->createPost(['caption' => 'hi', 'platforms' => ['x'], 'media' => $this->images(4), 'status' => 'posted'])
            ->assertStatus(201);

        $response = $this->createPost(['caption' => 'hi', 'platforms' => ['x'], 'media' => $this->images(5), 'status' => 'posted']);
        $response->assertStatus(422);
        $this->assertStringContainsString('X allows at most 4 images', $response->json('message'));
    }

    public function test_x_video_is_rejected_with_the_publish_job_wording(): void
    {
        $response = $this->createPost(['caption' => 'hi', 'platforms' => ['x'], 'media' => [$this->video()], 'status' => 'posted']);

        $response->assertStatus(422);
        $this->assertSame(
            "X can't post video from here yet — use images for X, or remove it from this post.",
            $response->json('message')
        );
    }

    // ── LinkedIn ──────────────────────────────────────────────────────────

    public function test_linkedin_video_is_rejected_but_images_are_fine(): void
    {
        $this->createPost(['caption' => 'hi', 'platforms' => ['linkedin'], 'media' => $this->images(2), 'status' => 'posted'])
            ->assertStatus(201);

        $response = $this->createPost(['caption' => 'hi', 'platforms' => ['linkedin'], 'media' => [$this->video()], 'status' => 'posted']);
        $response->assertStatus(422);
        $this->assertStringContainsString('LinkedIn', $response->json('message'));
        $this->assertStringContainsString('video', $response->json('message'));
    }

    // ── Bluesky ───────────────────────────────────────────────────────────

    public function test_bluesky_rejects_more_than_four_images(): void
    {
        $this->createPost(['caption' => 'hi', 'platforms' => ['bluesky'], 'media' => $this->images(4), 'status' => 'posted'])
            ->assertStatus(201);

        $response = $this->createPost(['caption' => 'hi', 'platforms' => ['bluesky'], 'media' => $this->images(5), 'status' => 'posted']);
        $response->assertStatus(422);
        $this->assertStringContainsString('Bluesky allows at most 4 images', $response->json('message'));
    }

    public function test_bluesky_rejects_video_until_the_video_path_exists(): void
    {
        $response = $this->createPost(['caption' => 'hi', 'platforms' => ['bluesky'], 'media' => [$this->video()], 'status' => 'posted']);

        $response->assertStatus(422);
        $this->assertStringContainsString("Bluesky can't post video from here yet", $response->json('message'));
    }

    // ── General ───────────────────────────────────────────────────────────

    public function test_rules_apply_per_platform_and_name_the_offender(): void
    {
        // Five images are fine for Instagram, one too many for X.
        $response = $this->createPost(['caption' => 'hi', 'platforms' => ['instagram', 'x'], 'media' => $this->images(5), 'status' => 'posted']);

        $response->assertStatus(422);
        $this->assertStringContainsString('X allows', $response->json('message'));
    }

    public function test_media_entries_without_a_url_or_path_do_not_count(): void
    {
        $response = $this->createPost($this->scheduled([
            'caption' => 'hi', 'platforms' => ['instagram'], 'media' => [['type' => 'image']],
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('Instagram', $response->json('message'));
    }

    public function test_publishing_a_saved_draft_checks_the_saved_media(): void
    {
        $post = $this->user->posts()->create([
            'caption' => 'hi', 'media' => $this->images(5), 'status' => Post::STATUS_DRAFT,
        ]);
        $post->targets()->create(['platform' => 'x', 'status' => 'pending']);

        // No media in the payload: the saved five images are what would go out.
        $response = $this->withHeaders($this->auth())->putJson("/api/posts/{$post->id}", ['status' => 'posted']);
        $response->assertStatus(422);
        $this->assertStringContainsString('X allows at most 4 images', $response->json('message'));
        $this->assertSame(Post::STATUS_DRAFT, $post->fresh()->status);

        // Trimming the media in the same request is enough.
        $this->withHeaders($this->auth())
            ->putJson("/api/posts/{$post->id}", ['status' => 'posted', 'media' => $this->images(4)])
            ->assertOk();
    }

    public function test_existing_tiktok_and_youtube_checks_still_apply(): void
    {
        $this->createPost(['caption' => 'hi', 'platforms' => ['youtube'], 'media' => $this->images(1), 'status' => 'posted'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'YouTube requires an uploaded video before publishing or scheduling.']);

        $this->createPost(['caption' => 'hi', 'platforms' => ['tiktok'], 'media' => [], 'status' => 'posted'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'TikTok requires an uploaded video or one or more images before publishing or scheduling.']);
    }

    // ── TikTok photo sizes ────────────────────────────────────────────────

    private function fakeMediaDisk(): void
    {
        config(['filesystems.media_disk' => 'r2test']);
        Storage::fake('r2test');
    }

    /** A stored PNG of the given size on the (faked) media disk, as a media entry. */
    private function storedImage(string $name, int $width, int $height): array
    {
        $img = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($img);
        Storage::disk('r2test')->put("posts/1/{$name}.png", ob_get_clean());
        imagedestroy($img);

        // No 'disk' key: the post payload's validation keeps type/url/path only,
        // so the check reads from the media disk, as the publish jobs do.
        return ['type' => 'image', 'url' => "https://cdn.example/{$name}.png", 'path' => "posts/1/{$name}.png"];
    }

    private function tiktokPhotos(array $media, string $status = 'scheduled')
    {
        $payload = ['caption' => 'hi', 'platforms' => ['tiktok'], 'media' => $media, 'options' => ['tiktok' => ['privacy_level' => 'SELF_ONLY']]];

        return $this->createPost($status === 'draft' ? $payload + ['status' => 'draft'] : $this->scheduled($payload));
    }

    public function test_tiktok_photo_over_1080_on_the_shorter_side_is_rejected_and_named(): void
    {
        $this->fakeMediaDisk();
        $media = [$this->storedImage('ok', 1080, 1920), $this->storedImage('big', 1200, 1600)];

        $response = $this->tiktokPhotos($media);

        $response->assertStatus(422);
        $this->assertSame(
            'Image 2 of 2 is 1200x1600 pixels. TikTok allows at most 1080px on the shorter side of a photo. Resize it and upload it again.',
            $response->json('message'),
        );
        $this->assertSame(0, Post::count());
    }

    public function test_tiktok_photos_at_or_under_1080_on_the_shorter_side_are_accepted(): void
    {
        $this->fakeMediaDisk();

        $this->tiktokPhotos([$this->storedImage('portrait', 1080, 1920), $this->storedImage('landscape', 1920, 1080)])
            ->assertStatus(201);
    }

    public function test_tiktok_photo_size_is_not_checked_on_drafts_or_video_posts(): void
    {
        $this->fakeMediaDisk();
        $big = $this->storedImage('big', 2172, 2896);

        $this->tiktokPhotos([$big], 'draft')->assertStatus(201);
        // A video post sends the video, not the images.
        $this->tiktokPhotos([$this->video(), $big])->assertStatus(201);
    }

    public function test_tiktok_photo_whose_size_cannot_be_read_is_allowed_and_logged(): void
    {
        $this->fakeMediaDisk();
        Storage::disk('r2test')->put('posts/1/broken.png', 'not an image');
        Log::spy();

        $this->tiktokPhotos([
            ['type' => 'image', 'url' => 'https://cdn.example/broken.png', 'path' => 'posts/1/broken.png'],
            ['type' => 'image', 'url' => 'https://cdn.example/missing.png', 'path' => 'posts/1/missing.png'],
            ['type' => 'image', 'url' => 'https://cdn.example/external.png'], // no path: never fetched
        ])->assertStatus(201);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'could not read image dimensions'))
            ->twice();
    }
}
