<?php

namespace Tests\Unit;

use App\Services\Social\Bluesky\BlobImage;
use PHPUnit\Framework\TestCase;

/**
 * Images must fit under Bluesky's 1 MB blob limit: big ones are
 * shrunk 10% per pass, small ones go out untouched, and the final
 * dimensions are reported for the embed's aspectRatio.
 */
class BlueskyBlobImageTest extends TestCase
{
    /** Noise compresses badly, so a 3000x2000 PNG comfortably exceeds 1 MB. */
    private function noisyImage(int $w, int $h, string $format, int $pixels = 200000): string
    {
        mt_srand(42);
        $im = imagecreatetruecolor($w, $h);
        for ($i = 0; $i < $pixels; $i++) {
            imagesetpixel($im, mt_rand(0, $w - 1), mt_rand(0, $h - 1), imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
        ob_start();
        $format === 'png' ? imagepng($im) : imagejpeg($im, null, 100);

        return (string) ob_get_clean();
    }

    public function test_small_images_are_sent_as_is_with_their_dimensions(): void
    {
        $png = $this->noisyImage(40, 30, 'png', 10);

        $out = BlobImage::prepare($png, 'application/octet-stream');

        $this->assertSame($png, $out['body']);
        $this->assertSame('image/png', $out['mime']);
        $this->assertSame([40, 30], [$out['width'], $out['height']]);
    }

    public function test_oversized_images_are_shrunk_until_they_fit(): void
    {
        $jpeg = $this->noisyImage(3000, 2000, 'jpeg');
        $this->assertGreaterThan(BlobImage::MAX_BYTES, strlen($jpeg));

        $out = BlobImage::prepare($jpeg, 'image/jpeg');

        $this->assertLessThanOrEqual(BlobImage::MAX_BYTES, strlen($out['body']));
        $this->assertSame('image/jpeg', $out['mime']);
        $this->assertLessThan(3000, $out['width']);
        $this->assertEqualsWithDelta(1.5, $out['width'] / $out['height'], 0.01, 'aspect ratio is kept');
        $this->assertSame([$out['width'], $out['height']], array_slice(getimagesizefromstring($out['body']), 0, 2));
    }

    public function test_oversized_png_becomes_jpeg_when_png_will_not_fit(): void
    {
        $png = $this->noisyImage(3000, 2000, 'png');
        $this->assertGreaterThan(BlobImage::MAX_BYTES, strlen($png));

        $out = BlobImage::prepare($png, 'image/png');

        $this->assertLessThanOrEqual(BlobImage::MAX_BYTES, strlen($out['body']));
        $this->assertSame('image/jpeg', $out['mime']);
    }

    public function test_unreadable_bytes_are_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a readable image');

        BlobImage::prepare('definitely not an image', 'image/png');
    }
}
