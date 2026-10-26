<?php

namespace App\Support;

/**
 * Where Gemini gets the video from. YouTube videos are fetched by Google from
 * the public watch URL; anything else (TikTok / Instagram CDN links from
 * CaptAPI) is a file we download and upload through the Gemini File API.
 */
final class VideoSource
{
    public const YOUTUBE = 'youtube';

    public const REMOTE = 'remote';

    private function __construct(
        public readonly string $kind,
        public readonly string $url,
        public readonly string $mime = 'video/mp4',
    ) {}

    public static function youtube(string $videoId): self
    {
        return new self(self::YOUTUBE, "https://www.youtube.com/watch?v={$videoId}");
    }

    public static function remote(string $url, string $mime = 'video/mp4'): self
    {
        return new self(self::REMOTE, $url, $mime);
    }

    public function isYoutube(): bool
    {
        return $this->kind === self::YOUTUBE;
    }
}
