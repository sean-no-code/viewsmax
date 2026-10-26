<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The only way to fetch a URL a user gave us ("import from URL", MCP
 * upload_media, base images). Stops SSRF: http(s) only, no credentials in
 * the URL, every address the host resolves to must be public (no loopback,
 * private, link-local — the EC2 metadata service at 169.254.169.254 — CGNAT
 * or reserved ranges), redirects are followed by hand and re-checked hop by
 * hop, the connection is pinned to the checked IP (no DNS rebinding between
 * check and connect), and the body is capped.
 */
final class SafeHttp
{
    private const MAX_REDIRECTS = 3;

    /** @var (callable(string): string[])|null test hook: host → IPs */
    private static $resolver = null;

    /**
     * @return array{body: string, content_type: ?string, url: string}
     *
     * @throws UnsafeUrlException when the URL (or a redirect) isn't a public http(s) address,
     *                            the response is too large, or the fetch fails
     */
    public static function get(string $url, int $maxBytes = 15 * 1024 * 1024, int $timeout = 15): array
    {
        [$response, $url] = self::fetch($url, $maxBytes, $timeout, null);

        $body = $response->body();
        if (strlen($body) > $maxBytes) {
            throw new UnsafeUrlException('That file is too large.');
        }

        return ['body' => $body, 'content_type' => $response->header('Content-Type') ?: null, 'url' => $url];
    }

    /**
     * Same guards as get(), but the body streams straight to $destPath instead of
     * into memory — for video files (hundreds of MB) a worker can't hold as a string.
     * The file is removed again on every failure.
     *
     * @return array{content_type: ?string, bytes: int, url: string}
     *
     * @throws UnsafeUrlException
     */
    public static function download(string $url, string $destPath, int $maxBytes = 200 * 1024 * 1024, int $timeout = 120): array
    {
        try {
            [$response, $url] = self::fetch($url, $maxBytes, $timeout, $destPath);

            clearstatcache(true, $destPath);
            $bytes = (int) filesize($destPath);
            if ($bytes > $maxBytes) {
                throw new UnsafeUrlException('That file is too large.');
            }
        } catch (UnsafeUrlException $e) {
            @unlink($destPath);
            throw $e;
        }

        return ['content_type' => $response->header('Content-Type') ?: null, 'bytes' => $bytes, 'url' => $url];
    }

    /**
     * The vetted request loop shared by get() and download(): every hop is
     * checked, pinned and capped; with $sink the body is written to that file.
     *
     * @return array{0: Response, 1: string} the final response and the URL it came from
     */
    private static function fetch(string $url, int $maxBytes, int $timeout, ?string $sink): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $port, $ip] = self::assertPublic($url);

            $request = Http::withoutRedirecting()
                ->timeout($timeout)
                ->connectTimeout(5)
                ->withOptions([
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
                    'progress' => function ($total, $downloaded) use ($maxBytes) {
                        if ($total > $maxBytes || $downloaded > $maxBytes) {
                            throw new UnsafeUrlException('That file is too large.');
                        }
                    },
                ]);
            if ($sink !== null) {
                // Guzzle opens the sink 'w+', so a redirect hop's empty body is overwritten by the next.
                $request = $request->sink($sink);
            }

            try {
                $response = $request->get($url);
            } catch (ConnectionException) {
                throw new UnsafeUrlException('We couldn\'t download that URL.');
            }

            if ($response->redirect()) {
                $location = (string) $response->header('Location');
                if ($location === '') {
                    throw new UnsafeUrlException('We couldn\'t download that URL.');
                }
                $url = self::absolute($location, $url);

                continue;
            }

            if (! $response->successful()) {
                throw new UnsafeUrlException("That URL returned HTTP {$response->status()}.");
            }

            return [$response, $url];
        }

        throw new UnsafeUrlException('That URL redirects too many times.');
    }

    /**
     * @return array{0: string, 1: int, 2: string} host, port, and the vetted IP to connect to
     *
     * @throws UnsafeUrlException
     */
    public static function assertPublic(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('Only public http(s) URLs are allowed.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if ($ips === []) {
            throw new UnsafeUrlException('That URL\'s host could not be found.');
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new UnsafeUrlException('Only public http(s) URLs are allowed.');
            }
        }

        return [$host, (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)), $ips[0]];
    }

    /** @param  (callable(string): string[])|null  $resolver */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    private static function resolve(string $host): array
    {
        if (self::$resolver) {
            return (self::$resolver)($host);
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }

    private static function isPublicIp(string $ip): bool
    {
        // ::ffff:a.b.c.d — judge the embedded IPv4 address.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // Ranges PHP's flags don't cover: CGNAT 100.64/10, benchmarking 198.18/15, IPv6 ULA/link-local.
        $long = ip2long($ip);
        if ($long !== false) {
            return ! (($long & 0xFFC00000) === ip2long('100.64.0.0') || ($long & 0xFFFE0000) === ip2long('198.18.0.0'));
        }

        return ! preg_match('/^(fc|fd|fe8|fe9|fea|feb)/i', $ip);
    }

    private static function absolute(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $b = parse_url($base);
        if (str_starts_with($location, '//')) {
            return $b['scheme'].':'.$location;
        }
        $origin = $b['scheme'].'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');

        return str_starts_with($location, '/') ? $origin.$location : $origin.'/'.ltrim($location, '/');
    }
}
