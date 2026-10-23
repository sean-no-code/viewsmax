<?php

namespace App\Services\Social\Bluesky;

/**
 * Server-side port of @atproto/api RichText::detectFacets: finds
 * the URLs, @handles and #hashtags in a post's text and returns them as
 * app.bsky.richtext.facet entries. Bluesky stores the plain text and renders
 * links only from these facets, so without them nothing is clickable.
 *
 * Offsets are UTF-8 byte offsets, which is exactly what PCRE's
 * PREG_OFFSET_CAPTURE reports on a UTF-8 string — no conversion needed.
 * Pure: handle → DID resolution is injected so this stays unit-testable.
 *
 * Accepted drift from @atproto/api: a bare domain (no scheme) or a handle is
 * taken as valid when its TLD is two or more letters instead of being checked
 * against the full IANA list.
 */
final class RichTextFacets
{
    private const MENTION_REGEX = '/(^|\s|\()(@)([a-zA-Z0-9.-]+)(\b)/u';

    private const URL_REGEX = '/(^|\s|\()((https?:\/\/\S+)|((?<domain>[a-z][a-z0-9]*(\.[a-z0-9]+)+)\S*))/iu';

    private const TAG_REGEX = '/(^|\s)[#＃]((?!\x{fe0f})[^\s\x{00AD}\x{2060}\x{200A}\x{200B}\x{200C}\x{200D}\x{20e2}]*[^\d\s\p{P}\x{00AD}\x{2060}\x{200A}\x{200B}\x{200C}\x{200D}\x{20e2}]+)?/u';

    /** Inclusive of the "#", a tag may be at most 64 characters (66 in @atproto/api's check). */
    private const TAG_MAX_LENGTH = 66;

    /**
     * @param  callable(string): ?string  $resolveHandle  handle → DID, null to drop the mention
     * @return list<array{index: array{byteStart:int, byteEnd:int}, features: list<array<string, string>>}>
     */
    public static function detect(string $text, callable $resolveHandle): array
    {
        return array_merge(
            self::mentions($text, $resolveHandle),
            self::links($text),
            self::tags($text),
        );
    }

    private static function mentions(string $text, callable $resolveHandle): array
    {
        preg_match_all(self::MENTION_REGEX, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $facets = [];
        foreach ($matches as $m) {
            $handle = $m[3][0];
            if (! self::isValidDomain($handle) && ! str_ends_with($handle, '.test')) {
                continue; // probably not a handle
            }
            $did = $resolveHandle($handle);
            if (! $did) {
                continue; // unresolved handles stay plain text
            }
            $start = $m[3][1] - 1; // include the "@"
            $facets[] = self::facet($start, $m[3][1] + strlen($handle), [
                '$type' => 'app.bsky.richtext.facet#mention',
                'did' => $did,
            ]);
        }

        return $facets;
    }

    private static function links(string $text): array
    {
        preg_match_all(self::URL_REGEX, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $facets = [];
        foreach ($matches as $m) {
            $uri = $m[2][0];
            $start = $m[2][1];
            if (! str_starts_with(strtolower($uri), 'http')) {
                $domain = $m['domain'][0] ?? '';
                if ($domain === '' || ! self::isValidDomain($domain)) {
                    continue;
                }
                $uri = 'https://'.$uri;
            }
            $end = $start + strlen($m[2][0]);
            // Strip ending punctuation ("see https://x.com." / "(https://x.com)").
            if (preg_match('/[.,;:!?]$/', $uri)) {
                $uri = substr($uri, 0, -1);
                $end--;
            }
            if (str_ends_with($uri, ')') && ! str_contains($uri, '(')) {
                $uri = substr($uri, 0, -1);
                $end--;
            }
            $facets[] = self::facet($start, $end, [
                '$type' => 'app.bsky.richtext.facet#link',
                'uri' => $uri,
            ]);
        }

        return $facets;
    }

    private static function tags(string $text): array
    {
        preg_match_all(self::TAG_REGEX, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $facets = [];
        foreach ($matches as $m) {
            $tag = $m[0][0];
            $hasLeadingSpace = preg_match('/^\s/u', $tag) === 1;
            $tag = preg_replace('/\p{P}+$/u', '', trim($tag));
            if ($tag === '' || mb_strlen($tag, 'UTF-8') > self::TAG_MAX_LENGTH) {
                continue;
            }
            $start = $m[0][1] + ($hasLeadingSpace ? strlen(mb_substr($m[0][0], 0, 1, 'UTF-8')) : 0);
            $facets[] = self::facet($start, $start + strlen($tag), [
                '$type' => 'app.bsky.richtext.facet#tag',
                'tag' => preg_replace('/^[#＃]/u', '', $tag),
            ]);
        }

        return $facets;
    }

    private static function facet(int $byteStart, int $byteEnd, array $feature): array
    {
        return [
            'index' => ['byteStart' => $byteStart, 'byteEnd' => $byteEnd],
            'features' => [$feature],
        ];
    }

    /** Looks like a domain: dotted labels with an alphabetic TLD of 2+ letters. */
    private static function isValidDomain(string $domain): bool
    {
        $labels = explode('.', $domain);

        return count($labels) >= 2
            && ! in_array('', $labels, true)
            && preg_match('/^[a-z]{2,}$/i', end($labels)) === 1;
    }
}
