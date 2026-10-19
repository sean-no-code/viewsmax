<?php

namespace App\Support;

/**
 * An image downloaded from a user-supplied URL: fetched through SafeHttp, and
 * accepted only if the bytes are really a JPEG/PNG/WebP/GIF. The extension
 * comes from the detected type — never from the URL, which an attacker
 * controls (".../shell.php").
 */
final class UploadedImage
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * @return array{body: string, mime: string, extension: string}
     *
     * @throws UnsafeUrlException
     */
    public static function fromUrl(string $url, int $maxBytes = 10 * 1024 * 1024): array
    {
        $body = SafeHttp::get($url, $maxBytes)['body'];

        $info = @getimagesizefromstring($body);
        $mime = $info['mime'] ?? null;
        if (! $mime || ! isset(self::EXTENSIONS[$mime])) {
            throw new UnsafeUrlException('That URL doesn\'t point to a JPEG, PNG, WebP or GIF image.');
        }

        return ['body' => $body, 'mime' => $mime, 'extension' => self::EXTENSIONS[$mime]];
    }
}
