<?php

namespace Tests\Unit;

use App\Services\PlatformClassifier;
use PHPUnit\Framework\TestCase;

class PlatformClassifierTest extends TestCase
{
    public function test_empty_referrer_is_direct(): void
    {
        $this->assertSame('direct', PlatformClassifier::fromReferrer(null));
        $this->assertSame('direct', PlatformClassifier::fromReferrer(''));
        $this->assertSame('direct', PlatformClassifier::fromReferrer('   '));
    }

    /** @dataProvider referrerProvider */
    public function test_classifies_known_referrers(string $referrer, string $expected): void
    {
        $this->assertSame($expected, PlatformClassifier::fromReferrer($referrer));
    }

    public static function referrerProvider(): array
    {
        return [
            ['https://www.instagram.com/', 'instagram'],
            ['https://l.instagram.com/?u=...', 'instagram'],
            ['https://www.tiktok.com/@x', 'tiktok'],
            ['https://m.youtube.com/watch?v=1', 'youtube'],
            ['https://youtu.be/abc', 'youtube'],
            ['https://t.co/abcd', 'x'],
            ['https://x.com/home', 'x'],
            ['https://twitter.com/home', 'x'],
            ['https://www.linkedin.com/feed', 'linkedin'],
            ['https://lnkd.in/xyz', 'linkedin'],
            ['https://l.facebook.com/l.php', 'facebook'],
            ['https://www.threads.net/@x', 'threads'],
            ['https://www.google.com/search?q=x', 'google'],
            ['https://www.google.co.uk/', 'google'],
            ['https://old.reddit.com/r/x', 'reddit'],
            ['https://some-random-blog.example/post', 'other'],
        ];
    }

    public function test_for_click_prefers_recognised_utm_source(): void
    {
        // utm_source wins over an unrecognised/empty referrer.
        $this->assertSame('instagram', PlatformClassifier::forClick('https://random.example', 'ig'));
        $this->assertSame('tiktok', PlatformClassifier::forClick(null, 'TikTok'));
        // Unrecognised utm falls through to the referrer classifier.
        $this->assertSame('youtube', PlatformClassifier::forClick('https://youtu.be/x', 'newsletter'));
        // Nothing known at all → direct.
        $this->assertSame('direct', PlatformClassifier::forClick(null, null));
    }
}
