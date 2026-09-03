<?php

namespace App\Services;

use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

/**
 * Presigned direct-to-R2 (S3-compatible) uploads for post media.
 *
 * The browser uploads straight to the media disk's bucket via URLs signed
 * here; the Laravel server never relays file bytes. Signing is local (no
 * network); only createMultipartUpload / complete / abort / stat hit the API.
 */
class PostMediaDirectUpload
{
    /** Files at or below this use a single presigned PUT; above it, multipart. */
    public const MULTIPART_THRESHOLD = 32 * 1024 * 1024;

    /**
     * R2 requires all multipart parts except the last to be the SAME size
     * (stricter than AWS S3), so the FE must slice at exactly this boundary.
     */
    public const PART_SIZE = 16 * 1024 * 1024;

    /** How long presigned URLs stay valid — generous for slow uplinks. */
    private const EXPIRY = '+60 minutes';

    public function diskName(): string
    {
        return config('filesystems.media_disk') ?: config('filesystems.default');
    }

    /** Direct upload only works when the media disk is S3-compatible (R2/S3). */
    public function available(): bool
    {
        return Storage::disk($this->diskName()) instanceof AwsS3V3Adapter;
    }

    /** @return array{url: string, headers: array<string, string>} */
    public function presignPut(string $path, string $mime): array
    {
        /** @var AwsS3V3Adapter $disk */
        $disk = Storage::disk($this->diskName());

        $signed = $disk->temporaryUploadUrl($path, now()->addHour(), ['ContentType' => $mime]);

        // Host header isn't something the browser can set; Content-Type is the
        // one that matters (it is part of the signature, so it is enforced).
        return [
            'url' => $signed['url'],
            'headers' => ['Content-Type' => $mime],
        ];
    }

    public function createMultipart(string $path, string $mime): string
    {
        $result = $this->client()->createMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $path,
            'ContentType' => $mime,
        ]);

        return $result['UploadId'];
    }

    public function presignPart(string $path, string $uploadId, int $partNumber): string
    {
        $command = $this->client()->getCommand('UploadPart', [
            'Bucket' => $this->bucket(),
            'Key' => $path,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);

        return (string) $this->client()->createPresignedRequest($command, self::EXPIRY)->getUri();
    }

    /** @param array<int, array{part_number: int, etag: string}> $parts */
    public function completeMultipart(string $path, string $uploadId, array $parts): void
    {
        $this->client()->completeMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $path,
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => array_map(fn (array $part) => [
                    'PartNumber' => $part['part_number'],
                    'ETag' => $part['etag'],
                ], $parts),
            ],
        ]);
    }

    public function abortMultipart(string $path, string $uploadId): void
    {
        $this->client()->abortMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $path,
            'UploadId' => $uploadId,
        ]);
    }

    /** @return array{bytes: int, mime: string} */
    public function stat(string $path): array
    {
        $disk = Storage::disk($this->diskName());

        return [
            'bytes' => $disk->size($path),
            'mime' => (string) $disk->mimeType($path),
        ];
    }

    public function delete(string $path): void
    {
        Storage::disk($this->diskName())->delete($path);
    }

    public function publicUrl(string $path): string
    {
        $url = Storage::disk($this->diskName())->url($path);

        return preg_match('#^https?://#i', $url) ? $url : url($url);
    }

    private function client(): S3Client
    {
        /** @var AwsS3V3Adapter $disk */
        $disk = Storage::disk($this->diskName());

        return $disk->getClient();
    }

    private function bucket(): string
    {
        return (string) config('filesystems.disks.' . $this->diskName() . '.bucket');
    }
}
