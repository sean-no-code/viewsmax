<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PostMediaDirectUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class PostMediaDirectUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    private function auth(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ];
    }

    /** Mock the presigning service (never hits the network in feature tests). */
    private function mockUploader(callable $expectations): MockInterface
    {
        return $this->mock(PostMediaDirectUpload::class, $expectations);
    }

    public function test_returns_relay_strategy_when_direct_upload_unavailable(): void
    {
        $this->mockUploader(fn (MockInterface $m) => $m->shouldReceive('available')->andReturn(false));

        $this->withHeaders($this->auth())->postJson('/api/posts/media/direct', [
            'type' => 'image',
            'filename' => 'thumb.jpg',
            'mime' => 'image/jpeg',
            'size' => 1024,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.strategy', 'relay');
    }

    public function test_small_image_gets_single_put_strategy(): void
    {
        $this->mockUploader(function (MockInterface $m) {
            $m->shouldReceive('available')->andReturn(true);
            $m->shouldReceive('presignPut')
                ->once()
                ->withArgs(fn (string $path, string $mime) => str_starts_with($path, "posts/{$this->user->id}/")
                    && str_ends_with($path, '.png')
                    && $mime === 'image/png')
                ->andReturn([
                    'url' => 'https://r2.example/presigned-put',
                    'headers' => ['Content-Type' => 'image/png'],
                ]);
            $m->shouldReceive('publicUrl')->andReturn('https://media.example/posts/x.png');
        });

        $this->withHeaders($this->auth())->postJson('/api/posts/media/direct', [
            'type' => 'image',
            'filename' => 'pixel.png',
            'mime' => 'image/png',
            'size' => 5 * 1024 * 1024,
        ])
            ->assertOk()
            ->assertJsonPath('data.strategy', 'put')
            ->assertJsonPath('data.url', 'https://r2.example/presigned-put')
            ->assertJsonPath('data.headers.Content-Type', 'image/png')
            ->assertJsonPath('data.public_url', 'https://media.example/posts/x.png');
    }

    public function test_large_video_gets_multipart_strategy_with_sequential_parts(): void
    {
        $size = 100 * 1024 * 1024; // 100MB -> ceil(100/16) = 7 parts of 16MB
        $expectedParts = (int) ceil($size / PostMediaDirectUpload::PART_SIZE);

        $this->mockUploader(function (MockInterface $m) use ($expectedParts) {
            $m->shouldReceive('available')->andReturn(true);
            $m->shouldReceive('createMultipart')
                ->once()
                ->withArgs(fn (string $path, string $mime) => str_ends_with($path, '.mp4') && $mime === 'video/mp4')
                ->andReturn('upload-123');
            $m->shouldReceive('presignPart')
                ->times($expectedParts)
                ->andReturnUsing(fn (string $path, string $uploadId, int $n) => "https://r2.example/part/{$n}");
            $m->shouldReceive('publicUrl')->andReturn('https://media.example/posts/x.mp4');
        });

        $response = $this->withHeaders($this->auth())->postJson('/api/posts/media/direct', [
            'type' => 'video',
            'filename' => 'clip.mp4',
            'mime' => 'video/mp4',
            'size' => $size,
        ])
            ->assertOk()
            ->assertJsonPath('data.strategy', 'multipart')
            ->assertJsonPath('data.upload_id', 'upload-123')
            ->assertJsonPath('data.part_size', PostMediaDirectUpload::PART_SIZE)
            ->assertJsonCount($expectedParts, 'data.parts');

        $parts = $response->json('data.parts');
        $this->assertSame(1, $parts[0]['part_number']);
        $this->assertSame('https://r2.example/part/1', $parts[0]['url']);
        $this->assertSame($expectedParts, end($parts)['part_number']);
    }

    public function test_mime_must_match_declared_type(): void
    {
        $this->mockUploader(fn (MockInterface $m) => $m->shouldReceive('available')->andReturn(true));

        $this->withHeaders($this->auth())->postJson('/api/posts/media/direct', [
            'type' => 'image',
            'filename' => 'clip.mp4',
            'mime' => 'video/mp4',
            'size' => 1024,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mime']);
    }

    public function test_oversized_video_is_rejected(): void
    {
        $this->mockUploader(fn (MockInterface $m) => $m->shouldReceive('available')->andReturn(true));

        $this->withHeaders($this->auth())->postJson('/api/posts/media/direct', [
            'type' => 'video',
            'filename' => 'huge.mp4',
            'mime' => 'video/mp4',
            'size' => 513 * 1024 * 1024, // over the 512MB video cap
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['size']);
    }

    public function test_oversized_image_is_rejected(): void
    {
        $this->mockUploader(fn (MockInterface $m) => $m->shouldReceive('available')->andReturn(true));

        $this->withHeaders($this->auth())->postJson('/api/posts/media/direct', [
            'type' => 'image',
            'filename' => 'big.jpg',
            'mime' => 'image/jpeg',
            'size' => 11 * 1024 * 1024, // over the 10MB image cap
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['size']);
    }

    public function test_complete_multipart_finishes_upload_and_returns_media_item(): void
    {
        $path = "posts/{$this->user->id}/abc.mp4";

        $this->mockUploader(function (MockInterface $m) use ($path) {
            $m->shouldReceive('completeMultipart')
                ->once()
                ->withArgs(fn (string $p, string $uploadId, array $parts) => $p === $path
                    && $uploadId === 'upload-123'
                    && $parts === [['part_number' => 1, 'etag' => '"etag-1"']]);
            $m->shouldReceive('stat')->with($path)->andReturn(['bytes' => 2048, 'mime' => 'video/mp4']);
            $m->shouldReceive('publicUrl')->with($path)->andReturn('https://media.example/' . $path);
            $m->shouldReceive('diskName')->andReturn('r2');
        });

        $this->withHeaders($this->auth())->postJson('/api/posts/media/direct/complete', [
            'type' => 'video',
            'path' => $path,
            'upload_id' => 'upload-123',
            'parts' => [['part_number' => 1, 'etag' => '"etag-1"']],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'video')
            ->assertJsonPath('data.url', 'https://media.example/' . $path)
            ->assertJsonPath('data.path', $path)
            ->assertJsonPath('data.disk', 'r2')
            ->assertJsonPath('data.bytes', 2048)
            ->assertJsonPath('data.mime', 'video/mp4');
    }

    public function test_complete_without_upload_id_skips_multipart_and_verifies_object(): void
    {
        $path = "posts/{$this->user->id}/abc.png";

        $this->mockUploader(function (MockInterface $m) use ($path) {
            $m->shouldReceive('completeMultipart')->never();
            $m->shouldReceive('stat')->with($path)->andReturn(['bytes' => 512, 'mime' => 'image/png']);
            $m->shouldReceive('publicUrl')->with($path)->andReturn('https://media.example/' . $path);
            $m->shouldReceive('diskName')->andReturn('r2');
        });

        $this->withHeaders($this->auth())->postJson('/api/posts/media/direct/complete', [
            'type' => 'image',
            'path' => $path,
        ])
            ->assertOk()
            ->assertJsonPath('data.bytes', 512);
    }

    public function test_complete_rejects_paths_outside_own_prefix(): void
    {
        $other = User::factory()->create();

        $this->mockUploader(function (MockInterface $m) {
            $m->shouldReceive('completeMultipart')->never();
            $m->shouldReceive('stat')->never();
        });

        $this->withHeaders($this->auth())->postJson('/api/posts/media/direct/complete', [
            'type' => 'video',
            'path' => "posts/{$other->id}/abc.mp4",
            'upload_id' => 'upload-123',
            'parts' => [['part_number' => 1, 'etag' => 'x']],
        ])
            ->assertStatus(403);
    }

    public function test_complete_deletes_and_rejects_object_over_size_cap(): void
    {
        $path = "posts/{$this->user->id}/abc.png";

        $this->mockUploader(function (MockInterface $m) use ($path) {
            // Object landed bigger than the image cap (presigned PUT can't enforce size).
            $m->shouldReceive('stat')->with($path)->andReturn(['bytes' => 11 * 1024 * 1024, 'mime' => 'image/png']);
            $m->shouldReceive('delete')->once()->with($path);
        });

        $this->withHeaders($this->auth())->postJson('/api/posts/media/direct/complete', [
            'type' => 'image',
            'path' => $path,
        ])
            ->assertStatus(422);
    }

    public function test_complete_deletes_and_rejects_mismatched_mime(): void
    {
        $path = "posts/{$this->user->id}/abc.png";

        $this->mockUploader(function (MockInterface $m) use ($path) {
            $m->shouldReceive('stat')->with($path)->andReturn(['bytes' => 512, 'mime' => 'application/octet-stream']);
            $m->shouldReceive('delete')->once()->with($path);
        });

        $this->withHeaders($this->auth())->postJson('/api/posts/media/direct/complete', [
            'type' => 'image',
            'path' => $path,
        ])
            ->assertStatus(422);
    }

    public function test_abort_aborts_multipart_upload(): void
    {
        $path = "posts/{$this->user->id}/abc.mp4";

        $this->mockUploader(function (MockInterface $m) use ($path) {
            $m->shouldReceive('abortMultipart')->once()->with($path, 'upload-123');
        });

        $this->withHeaders($this->auth())->postJson('/api/posts/media/direct/abort', [
            'path' => $path,
            'upload_id' => 'upload-123',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_abort_rejects_paths_outside_own_prefix(): void
    {
        $other = User::factory()->create();

        $this->mockUploader(fn (MockInterface $m) => $m->shouldReceive('abortMultipart')->never());

        $this->withHeaders($this->auth())->postJson('/api/posts/media/direct/abort', [
            'path' => "posts/{$other->id}/abc.mp4",
            'upload_id' => 'upload-123',
        ])
            ->assertStatus(403);
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->postJson('/api/posts/media/direct', ['type' => 'image'])->assertStatus(401);
        $this->postJson('/api/posts/media/direct/complete', ['path' => 'posts/1/x.png'])->assertStatus(401);
        $this->postJson('/api/posts/media/direct/abort', ['path' => 'posts/1/x.png'])->assertStatus(401);
    }
}
