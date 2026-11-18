<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Services\PostMediaDirectUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A post may only reference storage paths our upload endpoints handed to the
 * caller (posts/{user_id}/...). The publish jobs read media.*.path and the
 * YouTube thumbnail path straight from storage (Postiz 12db9bd2).
 */
class PostMediaOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.default' => 'local', 'filesystems.media_disk' => 'r2test']);
        Storage::fake('local');
        Storage::fake('r2test');
        Http::preventStrayRequests(); // never reach a real API
        $this->user = User::factory()->create();
    }

    private function auth(?User $user = null): array
    {
        return [
            'Authorization' => 'Bearer '.($user ?? $this->user)->createToken('mobile-app')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    private function draft(array $media, array $options = [])
    {
        return $this->withHeaders($this->auth())->postJson('/api/posts', array_filter([
            'caption' => 'Hello',
            'platforms' => ['x'],
            'media' => $media,
            'options' => $options,
        ]));
    }

    public function test_another_users_media_path_is_refused(): void
    {
        $other = User::factory()->create();

        $this->draft([['type' => 'video', 'url' => 'https://cdn.example/v.mp4', 'path' => "posts/{$other->id}/their-video.mp4"]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['media.0.path']);

        $this->assertSame(0, Post::count());
    }

    public function test_a_path_outside_the_posts_prefix_or_with_traversal_is_refused(): void
    {
        $id = $this->user->id;
        foreach (['generated-images/1/x.png', "posts/{$id}/../2/x.mp4", "/posts/{$id}/x.mp4", "posts/{$id}"] as $path) {
            $this->draft([['type' => 'image', 'url' => 'https://cdn.example/x.png', 'path' => $path]])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['media.0.path']);
        }

        $this->assertSame(0, Post::count());
    }

    public function test_another_users_youtube_thumbnail_path_is_refused(): void
    {
        $other = User::factory()->create();

        $this->draft([], ['youtube' => ['thumbnail' => ['url' => 'https://cdn.example/c.jpg', 'path' => "posts/{$other->id}/cover.jpg"]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['options.youtube.thumbnail.path']);
    }

    public function test_an_update_cannot_switch_to_another_users_path(): void
    {
        $other = User::factory()->create();
        $post = $this->draft([['type' => 'image', 'url' => 'https://cdn.example/a.png', 'path' => "posts/{$this->user->id}/a.png"]])
            ->assertCreated()->json('id');

        $this->withHeaders($this->auth())->putJson("/api/posts/{$post}", [
            'media' => [['type' => 'image', 'url' => 'https://cdn.example/b.png', 'path' => "posts/{$other->id}/b.png"]],
        ])->assertStatus(422)->assertJsonValidationErrors(['media.0.path']);

        $this->assertSame("posts/{$this->user->id}/a.png", Post::find($post)->media[0]['path']);
    }

    public function test_media_without_a_path_is_unaffected(): void
    {
        $this->draft([['type' => 'image', 'url' => 'https://elsewhere.example/a.png']])->assertCreated();
    }

    public function test_a_file_from_the_relay_upload_endpoint_is_accepted(): void
    {
        $uploaded = $this->withHeaders($this->auth())->post('/api/posts/media', [
            'type' => 'image',
            'file' => UploadedFile::fake()->image('cover.png'),
        ])->assertOk()->json('data');

        $this->draft([$uploaded], ['youtube' => ['thumbnail' => $uploaded]])->assertCreated();
    }

    public function test_a_file_from_the_direct_upload_session_is_accepted(): void
    {
        $this->mock(PostMediaDirectUpload::class, function (MockInterface $m) {
            $m->shouldReceive('available')->andReturn(true);
            $m->shouldReceive('presignPut')->andReturn(['url' => 'https://r2.example/put', 'headers' => []]);
            $m->shouldReceive('publicUrl')->andReturn('https://media.example/x.png');
        });

        $path = $this->withHeaders($this->auth())->postJson('/api/posts/media/direct', [
            'type' => 'image', 'filename' => 'x.png', 'mime' => 'image/png', 'size' => 1024,
        ])->assertOk()->json('data.path');

        $this->draft([['type' => 'image', 'url' => 'https://media.example/x.png', 'path' => $path]])->assertCreated();
    }
}
