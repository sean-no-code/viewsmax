<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ViewsMax\SeoEngine\Support\OutboundUrl;

class OutboundUrlTest extends TestCase
{
    /** @dataProvider blockedUrls */
    public function test_private_loopback_metadata_and_non_http_urls_are_rejected(string $url): void
    {
        $this->assertFalse(OutboundUrl::isPublic($url), $url);
    }

    public static function blockedUrls(): array
    {
        return [
            ['http://127.0.0.1/wp-json'],
            ['http://localhost:8000'],
            ['http://api.localhost'],
            ['http://169.254.169.254/latest/meta-data/'],
            ['http://10.0.0.5'],
            ['http://172.16.3.4'],
            ['http://192.168.1.1'],
            ['http://0.0.0.0'],
            ['http://[::1]/'],
            ['http://[fe80::1]/'],
            ['ftp://blog.example/x'],
            ['file:///etc/passwd'],
            ['not a url'],
            ['/relative/path'],
        ];
    }

    /** @dataProvider allowedUrls */
    public function test_public_http_urls_are_allowed(string $url): void
    {
        $this->assertTrue(OutboundUrl::isPublic($url), $url);
    }

    public static function allowedUrls(): array
    {
        return [
            ['https://blog.example/'],          // reserved TLD: never resolves, request fails on its own
            ['https://8.8.8.8/'],
            ['http://1.1.1.1/wp-json/wp/v2/posts'],
        ];
    }

    public function test_assert_public_throws_a_user_safe_message(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WordPress site URL must be a public http(s) address');

        OutboundUrl::assertPublic('http://127.0.0.1', 'WordPress site URL');
    }
}
