<?php

namespace Tests\Feature;

use App\Support\SafeHttp;
use App\Support\UnsafeUrlException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SafeHttp is the only way to fetch a URL a user gave us: http(s) only,
 * public addresses only (checked on every redirect hop, DNS pinned), and a
 * byte cap — so "import from URL" can't reach the EC2 metadata service,
 * localhost, or the private network.
 */
class SafeHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SafeHttp::resolveUsing(fn (string $host) => match ($host) {
            'img.test', 'cdn.test' => ['93.184.216.34'],
            'internal.test' => ['10.0.0.5'],
            'rebind.test' => ['93.184.216.34', '127.0.0.1'],
            default => [],
        });
    }

    protected function tearDown(): void
    {
        SafeHttp::resolveUsing(null);
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function blockedUrls(): array
    {
        return [
            'aws metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'loopback' => ['http://127.0.0.1:8000/'],
            'loopback v6' => ['http://[::1]/'],
            'v4-mapped v6' => ['http://[::ffff:127.0.0.1]/'],
            'private 10/8' => ['http://10.0.0.5/'],
            'private 192.168' => ['http://192.168.1.1/'],
            'cgnat' => ['http://100.64.0.1/'],
            'unspecified' => ['http://0.0.0.0/'],
            'host resolving private' => ['https://internal.test/x.png'],
            'any record private' => ['https://rebind.test/x.png'],
            'unresolvable' => ['https://nowhere.test/x.png'],
            'file scheme' => ['file:///etc/passwd'],
            'ftp scheme' => ['ftp://img.test/x.png'],
            'gopher scheme' => ['gopher://img.test/'],
            'credentials in url' => ['https://user:pass@img.test/x.png'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function test_refuses_non_public_destinations(string $url): void
    {
        Http::fake();

        try {
            SafeHttp::get($url);
            $this->fail("{$url} should have been refused");
        } catch (UnsafeUrlException) {
            Http::assertNothingSent();
        }
    }

    public function test_fetches_a_public_url(): void
    {
        Http::fake(['https://img.test/a.png' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);

        $response = SafeHttp::get('https://img.test/a.png');

        $this->assertSame('PNGDATA', $response['body']);
        $this->assertSame('image/png', $response['content_type']);
    }

    public function test_follows_a_redirect_to_another_public_host(): void
    {
        Http::fake([
            'https://img.test/a.png' => Http::response('', 302, ['Location' => 'https://cdn.test/real.png']),
            'https://cdn.test/real.png' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->assertSame('PNGDATA', SafeHttp::get('https://img.test/a.png')['body']);
    }

    public function test_refuses_a_redirect_into_the_metadata_service(): void
    {
        Http::fake([
            'https://img.test/a.png' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/iam/']),
            '169.254.169.254/*' => Http::response('SECRET', 200),
        ]);

        $this->expectException(UnsafeUrlException::class);

        try {
            SafeHttp::get('https://img.test/a.png');
        } finally {
            Http::assertNotSent(fn ($r) => str_contains($r->url(), '169.254.169.254'));
        }
    }

    public function test_stops_after_too_many_redirects(): void
    {
        Http::fake(['https://img.test/*' => Http::response('', 302, ['Location' => 'https://img.test/loop'])]);

        $this->expectException(UnsafeUrlException::class);
        SafeHttp::get('https://img.test/start');
    }

    public function test_refuses_a_body_over_the_cap(): void
    {
        Http::fake(['https://img.test/big.png' => Http::response(str_repeat('x', 2048), 200, ['Content-Type' => 'image/png'])]);

        $this->expectException(UnsafeUrlException::class);
        SafeHttp::get('https://img.test/big.png', maxBytes: 1024);
    }
}
