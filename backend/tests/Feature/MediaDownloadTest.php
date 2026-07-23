<?php

namespace Tests\Feature;

use App\Support\MediaProxy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The signed media proxy lets TikTok/Instagram fetch post media from our
 * verified domain (streamed from R2). The signature is the access control:
 * unsigned/tampered/expired links are refused, and only media disks are served.
 */
class MediaDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.media_disk' => 'r2', 'filesystems.default' => 'local']);
        Storage::fake('r2');
        Storage::fake('local');
    }

    public function test_signed_url_streams_the_media_file(): void
    {
        Storage::disk('r2')->put('posts/7/slide.jpg', 'image-bytes');

        $url = MediaProxy::url('posts/7/slide.jpg', 'r2');
        $response = $this->get($url);

        $response->assertOk();
        $this->assertSame('image-bytes', $response->streamedContent());
    }

    public function test_unsigned_request_is_forbidden(): void
    {
        Storage::disk('r2')->put('posts/7/slide.jpg', 'image-bytes');

        // route() with no signature — the signed middleware must reject it.
        $this->get(route('media.download', ['ref' => MediaProxy::ref('posts/7/slide.jpg', 'r2')]))
            ->assertForbidden();
    }

    public function test_tampered_ref_is_forbidden(): void
    {
        $url = MediaProxy::url('posts/7/slide.jpg', 'r2');
        // Swap the signed ref for a different one — the signature no longer matches.
        $tampered = str_replace(
            MediaProxy::ref('posts/7/slide.jpg', 'r2'),
            MediaProxy::ref('posts/7/other.jpg', 'r2'),
            $url,
        );

        $this->get($tampered)->assertForbidden();
    }

    public function test_missing_file_returns_404(): void
    {
        $this->get(MediaProxy::url('posts/7/nope.jpg', 'r2'))->assertNotFound();
    }

    public function test_disk_outside_the_media_disks_returns_404(): void
    {
        // A validly-signed link whose ref points at a non-media disk must not serve.
        $ref = MediaProxy::ref('secrets/keys.txt', 'private_secret');
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('media.download', now()->addHour(), ['ref' => $ref]);

        $this->get($url)->assertNotFound();
    }
}
