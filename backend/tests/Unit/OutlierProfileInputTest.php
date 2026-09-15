<?php

namespace Tests\Unit;

use App\Support\OutlierProfileInput;
use PHPUnit\Framework\TestCase;

class OutlierProfileInputTest extends TestCase
{
    /** @dataProvider profileInputs */
    public function test_parses_profile_urls_and_handles(?string $platform, string $input, array $expected): void
    {
        $this->assertSame($expected, OutlierProfileInput::parse($platform, $input));
    }

    public static function profileInputs(): array
    {
        return [
            // YouTube
            'yt handle url' => [null, 'https://www.youtube.com/@MrBeast', ['platform' => 'youtube', 'handle' => 'mrbeast', 'kind' => 'handle']],
            'yt handle url with tab' => [null, 'https://youtube.com/@MrBeast/videos', ['platform' => 'youtube', 'handle' => 'mrbeast', 'kind' => 'handle']],
            'yt channel id url' => [null, 'https://www.youtube.com/channel/UCX6OQ3DkcsbYNE6H8uQQuVA', ['platform' => 'youtube', 'handle' => 'UCX6OQ3DkcsbYNE6H8uQQuVA', 'kind' => 'channel_id']],
            'yt legacy user url' => [null, 'https://www.youtube.com/user/PewDiePie', ['platform' => 'youtube', 'handle' => 'pewdiepie', 'kind' => 'username']],
            'yt bare handle' => ['youtube', '@MrBeast', ['platform' => 'youtube', 'handle' => 'mrbeast', 'kind' => 'handle']],
            'yt bare handle no at' => ['youtube', 'MrBeast', ['platform' => 'youtube', 'handle' => 'mrbeast', 'kind' => 'handle']],
            // TikTok
            'tiktok profile url' => [null, 'https://www.tiktok.com/@khaby.lame', ['platform' => 'tiktok', 'handle' => 'khaby.lame', 'kind' => 'handle']],
            'tiktok profile url query' => [null, 'https://www.tiktok.com/@khaby.lame?lang=en', ['platform' => 'tiktok', 'handle' => 'khaby.lame', 'kind' => 'handle']],
            'tiktok bare handle' => ['tiktok', '@khaby.lame', ['platform' => 'tiktok', 'handle' => 'khaby.lame', 'kind' => 'handle']],
            // Instagram
            'ig profile url' => [null, 'https://www.instagram.com/nasa/', ['platform' => 'instagram', 'handle' => 'nasa', 'kind' => 'handle']],
            'ig profile url reels tab' => [null, 'https://instagram.com/nasa/reels/', ['platform' => 'instagram', 'handle' => 'nasa', 'kind' => 'handle']],
            'ig bare handle' => ['instagram', '@NASA', ['platform' => 'instagram', 'handle' => 'nasa', 'kind' => 'handle']],
            // Platform hint is ignored when the URL says otherwise
            'url wins over platform hint' => ['youtube', 'https://www.tiktok.com/@khaby.lame', ['platform' => 'tiktok', 'handle' => 'khaby.lame', 'kind' => 'handle']],
        ];
    }

    /** @dataProvider rejectedInputs */
    public function test_rejects_video_links_and_unknown_inputs(?string $platform, string $input, string $messageContains): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($messageContains, '/').'/i');

        OutlierProfileInput::parse($platform, $input);
    }

    public static function rejectedInputs(): array
    {
        return [
            'yt watch url' => [null, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'video link'],
            'yt shorts url' => [null, 'https://www.youtube.com/shorts/dQw4w9WgXcQ', 'video link'],
            'youtu.be url' => [null, 'https://youtu.be/dQw4w9WgXcQ', 'video link'],
            'tiktok video url' => [null, 'https://www.tiktok.com/@khaby.lame/video/7646812028874673439', 'video link'],
            'ig reel url' => [null, 'https://www.instagram.com/reel/DbYaLffE2DD/', 'video link'],
            'ig post url' => [null, 'https://www.instagram.com/p/DbYaLffE2DD/', 'video link'],
            'ig explore path' => [null, 'https://www.instagram.com/explore/', 'profile'],
            'yt custom c url' => [null, 'https://www.youtube.com/c/SomeChannel', '@handle'],
            'bare handle without platform' => [null, '@khaby.lame', 'platform'],
            'unknown host' => [null, 'https://vimeo.com/someone', 'YouTube, TikTok or Instagram'],
            'empty' => ['tiktok', '   ', 'handle'],
        ];
    }
}
