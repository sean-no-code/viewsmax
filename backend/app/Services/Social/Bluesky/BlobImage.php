<?php

namespace App\Services\Social\Bluesky;

use RuntimeException;

/**
 * Fits an image under Bluesky's 1 MB blob limit. While the bytes are over
 * ~976 KB, shrink both sides by 10% and re-encode. Photos become JPEG; a
 * PNG stays PNG only while that
 * still fits. Also reports the final dimensions for the embed's aspectRatio,
 * which feeds use to lay the image out without cropping or letterboxing.
 */
final class BlobImage
{
    public const MAX_BYTES = 976 * 1024;

    private const MIN_SIDE = 10;

    private const JPEG_QUALITY = 85;

    /**
     * @return array{body: string, mime: string, width: int, height: int}
     *
     * @throws RuntimeException when the bytes aren't a readable image
     */
    public static function prepare(string $bytes, ?string $mime = null): array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new RuntimeException('the file is not a readable image');
        }
        [$width, $height] = $info;
        $mime = $info['mime'] ?: ($mime ?: 'image/jpeg');

        if (strlen($bytes) <= self::MAX_BYTES) {
            return ['body' => $bytes, 'mime' => $mime, 'width' => $width, 'height' => $height];
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new RuntimeException('the image could not be decoded for resizing');
        }

        $keepPng = $mime === 'image/png';
        while (true) {
            $width = (int) floor($width * 0.9);
            $height = (int) floor($height * 0.9);
            if ($width < self::MIN_SIDE || $height < self::MIN_SIDE) {
                throw new RuntimeException('the image is too large to fit under 1 MB');
            }

            $resized = imagecreatetruecolor($width, $height);
            if ($keepPng) {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
            } else {
                // JPEG has no alpha: flatten transparent PNGs onto white.
                imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
            }
            imagecopyresampled($resized, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
            $source = $resized;

            if ($keepPng) {
                $bytes = self::encode(fn () => imagepng($resized, null, 9));
                if (strlen($bytes) <= self::MAX_BYTES) {
                    return ['body' => $bytes, 'mime' => 'image/png', 'width' => $width, 'height' => $height];
                }
                // PNG won't fit at a sensible size: fall back to JPEG from here on.
                $keepPng = false;
                $flat = imagecreatetruecolor($width, $height);
                imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
                imagecopy($flat, $resized, 0, 0, 0, 0, $width, $height);
                $source = $resized = $flat;
            }

            $bytes = self::encode(fn () => imagejpeg($resized, null, self::JPEG_QUALITY));
            if (strlen($bytes) <= self::MAX_BYTES) {
                return ['body' => $bytes, 'mime' => 'image/jpeg', 'width' => $width, 'height' => $height];
            }
        }
    }

    private static function encode(callable $write): string
    {
        ob_start();
        $write();

        return (string) ob_get_clean();
    }
}
