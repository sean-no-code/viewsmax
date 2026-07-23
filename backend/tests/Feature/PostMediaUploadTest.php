<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PostMediaUploadTest extends TestCase
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

    private function auth(array $extra = []): array
    {
        return array_merge([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ], $extra);
    }

    /**
     * Regression: an image upload previously 500'd with
     * "Method Illuminate\Validation\Validator::validateImage|max does not exist"
     * because the array-style ruleset kept 'image|max:10240' as a single element
     * (pipes are only split for string rulesets).
     */
    public function test_image_upload_succeeds(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->image('thumb.jpg', 640, 360);

        $this->withHeaders($this->auth())->post('/api/posts/media', [
            'type' => 'image',
            'file' => $file,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'image')
            ->assertJsonPath('data.mime', 'image/jpeg');
    }

    public function test_video_upload_succeeds(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4');

        $this->withHeaders($this->auth())->post('/api/posts/media', [
            'type' => 'video',
            'file' => $file,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'video');
    }

    public function test_oversized_image_is_rejected(): void
    {
        Storage::fake('local');

        // 11 MB exceeds the 10 MB (10240 KB) image limit.
        $file = UploadedFile::fake()->create('big.jpg', 11 * 1024, 'image/jpeg');

        $this->withHeaders($this->auth())->post('/api/posts/media', [
            'type' => 'image',
            'file' => $file,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/posts/media', ['type' => 'image'])->assertStatus(401);
    }
}
