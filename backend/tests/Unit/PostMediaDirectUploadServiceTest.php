<?php

namespace Tests\Unit;

use App\Services\PostMediaDirectUpload;
use Tests\TestCase;

class PostMediaDirectUploadServiceTest extends TestCase
{
    /** Point media_disk at an S3-compatible disk with dummy creds (signing is offline). */
    private function configureR2(): void
    {
        config([
            'filesystems.disks.r2' => [
                'driver' => 's3',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'region' => 'auto',
                'bucket' => 'test-bucket',
                'url' => 'https://media.example',
                'endpoint' => 'https://account.r2.cloudflarestorage.com',
                'use_path_style_endpoint' => true,
                'throw' => true,
            ],
            'filesystems.media_disk' => 'r2',
        ]);
    }

    public function test_unavailable_when_media_disk_is_local(): void
    {
        config(['filesystems.media_disk' => 'local']);

        $this->assertFalse(app(PostMediaDirectUpload::class)->available());
    }

    public function test_available_when_media_disk_is_s3_compatible(): void
    {
        $this->configureR2();

        $this->assertTrue(app(PostMediaDirectUpload::class)->available());
    }

    public function test_presign_put_returns_signed_url_with_content_type_header(): void
    {
        $this->configureR2();

        $signed = app(PostMediaDirectUpload::class)->presignPut('posts/1/x.png', 'image/png');

        $this->assertStringContainsString('X-Amz-Signature=', $signed['url']);
        $this->assertStringContainsString('test-bucket/posts/1/x.png', $signed['url']);
        $this->assertSame('image/png', $signed['headers']['Content-Type'] ?? null);
    }

    public function test_presign_part_returns_signed_upload_part_url(): void
    {
        $this->configureR2();

        $url = app(PostMediaDirectUpload::class)->presignPart('posts/1/x.mp4', 'upload-123', 3);

        $this->assertStringContainsString('partNumber=3', $url);
        $this->assertStringContainsString('uploadId=upload-123', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
    }

    public function test_public_url_uses_disk_url(): void
    {
        $this->configureR2();

        $this->assertSame(
            'https://media.example/posts/1/x.png',
            app(PostMediaDirectUpload::class)->publicUrl('posts/1/x.png')
        );
    }
}
