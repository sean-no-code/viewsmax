<?php

namespace Tests\Feature;

use App\Jobs\GenerateFlux2ImageJob;
use App\Models\GeneratedImage;
use App\Models\User;
use App\Support\SafeHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * POST /api/image/generate with base_image_url must fetch through SafeHttp
 * (Postiz 5f18f1ca): internal addresses and redirects to them are refused
 * before anything is requested or stored.
 */
class ImageGenerationUrlFetchTest extends TestCase
{
    use RefreshDatabase;

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }

    private function generateFrom(string $url)
    {
        $user = User::factory()->create();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken('mobile-app')->plainTextToken,
            'Accept' => 'application/json',
        ])->postJson('/api/image/generate', [
            'method' => GeneratedImage::METHOD_GENERATE,
            'base_image_url' => $url,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        Http::preventStrayRequests(); // never reach a real API
    }

    public function test_the_metadata_service_is_refused_without_a_request(): void
    {
        Http::fake();

        $this->generateFrom('http://169.254.169.254/latest/meta-data/')
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, GeneratedImage::count());
        Queue::assertNotPushed(GenerateFlux2ImageJob::class);
    }

    public function test_a_hostname_that_resolves_to_loopback_is_refused(): void
    {
        SafeHttp::resolveUsing(fn () => ['127.0.0.1']);
        Http::fake();

        $this->generateFrom('https://internal.example/a.png')->assertStatus(400);

        Http::assertNothingSent();
        $this->assertSame(0, GeneratedImage::count());
    }

    public function test_a_redirect_to_an_internal_address_is_refused(): void
    {
        Http::fake([
            'https://cdn.example/a.png' => Http::response('', 302, ['Location' => 'http://10.0.0.5/secret.png']),
            '*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->generateFrom('https://cdn.example/a.png')->assertStatus(400);

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '10.0.0.5'));
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, GeneratedImage::count());
    }

    public function test_a_public_image_url_is_downloaded_and_named_by_its_content(): void
    {
        Http::fake(['https://cdn.example/photo.php' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);

        $this->generateFrom('https://cdn.example/photo.php')->assertStatus(202);

        $image = GeneratedImage::sole();
        $this->assertSame('https://cdn.example/photo.php', $image->getRawOriginal('base_image_url'));
        $this->assertStringEndsWith('.png', $image->base_image_path);
        Storage::disk('public')->assertExists($image->base_image_path);
        Queue::assertPushed(GenerateFlux2ImageJob::class);
    }

    public function test_a_body_that_is_not_an_image_is_refused(): void
    {
        Http::fake(['https://cdn.example/a.png' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'image/png'])]);

        $this->generateFrom('https://cdn.example/a.png')->assertStatus(400);

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, GeneratedImage::count());
    }
}
