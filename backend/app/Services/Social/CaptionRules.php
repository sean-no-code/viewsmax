<?php

namespace App\Services\Social;

/**
 * Single source of truth for per-platform caption limits and X text rules:
 * t.co-aware length weighting and `---` thread splitting.
 *
 * The frontend mirrors this logic in tube-trend-tool/src/lib/text-metrics.ts —
 * any change to the counting algorithm, URL regex, or split regex must be made
 * in BOTH files or the composer and the API will disagree about validity.
 */
final class CaptionRules
{
    /** Per-platform caption limits — consumed by PostController, MCP tools, providers. */
    public const LIMITS = [
        'youtube' => 5000,
        'tiktok' => 2200,
        'instagram' => 2200,
        'x' => 280,
        'linkedin' => 3000,
        'facebook' => 5000,
        'threads' => 500,
        'bluesky' => 300,
    ];

    /** On X every URL counts as a fixed 23 chars (t.co wrapping). */
    public const X_URL_WEIGHT = 23;

    public const X_MAX_SEGMENTS = 25;

    public const X_MAX_IMAGES = 4;

    /** Keep character-for-character identical to X_URL_RE in text-metrics.ts. */
    public const X_URL_REGEX = '~(?:https?://|www\.)\S+~iu';

    /** A line that is exactly `---` (surrounding blanks allowed) splits X threads. */
    public const X_THREAD_DELIMITER_REGEX = '/^[ \t]*---[ \t]*$/m';

    public static function limit(string $platform): ?int
    {
        return self::LIMITS[$platform] ?? null;
    }

    /** Plain code-point count — matches the frontend's [...text].length. */
    public static function plainLength(string $text): int
    {
        return mb_strlen(self::normalize($text), 'UTF-8');
    }

    /**
     * t.co-aware weighted length for X: every URL counts 23, remaining code
     * points weigh 1 in twitter-text's "light" ranges and 2 otherwise (CJK,
     * emoji). Accepted drift from full twitter-text: bare domains without a
     * scheme or `www.` aren't weighted, and trailing punctuation glued to a
     * URL is absorbed into the 23 — both err on the conservative side.
     */
    public static function xLength(string $text): int
    {
        $text = self::normalize($text);
        if ($text === '') {
            return 0;
        }

        $urls = preg_match_all(self::X_URL_REGEX, $text);
        $rest = preg_replace(self::X_URL_REGEX, '', $text);

        $length = $urls * self::X_URL_WEIGHT;
        foreach (mb_str_split($rest, 1, 'UTF-8') as $char) {
            $cp = mb_ord($char, 'UTF-8');
            $light = $cp <= 4351                    // most Latin/Cyrillic/etc.
                || ($cp >= 8192 && $cp <= 8205)     // general punctuation spaces/joiners
                || ($cp >= 8208 && $cp <= 8223)     // dashes & quotes
                || ($cp >= 8242 && $cp <= 8247);    // primes
            $length += $light ? 1 : 2;
        }

        return $length;
    }

    /**
     * Split an X caption into thread segments on `---` lines.
     *
     * @return list<string> trimmed segments; blank input gives []
     */
    public static function splitXThread(string $text): array
    {
        $text = self::normalize($text);
        if (trim($text) === '') {
            return [];
        }

        return array_map('trim', preg_split(self::X_THREAD_DELIMITER_REGEX, $text));
    }

    /**
     * Validate an X caption as a thread.
     *
     * @return list<string> human-readable errors; empty means valid
     */
    public static function xThreadErrors(string $text): array
    {
        $segments = self::splitXThread($text);
        $errors = [];

        if (count($segments) > self::X_MAX_SEGMENTS) {
            $errors[] = 'Threads are limited to '.self::X_MAX_SEGMENTS.' tweets.';
        }

        $limit = self::LIMITS['x'];
        foreach ($segments as $i => $segment) {
            $n = $i + 1;
            if ($segment === '') {
                $errors[] = "Tweet {$n} in the thread is empty.";

                continue;
            }
            $over = self::xLength($segment) - $limit;
            if ($over > 0) {
                $chars = $over === 1 ? 'character' : 'characters';
                $errors[] = count($segments) === 1
                    ? "Caption is {$over} {$chars} over X's {$limit} limit. Add a line with --- to split it into a thread."
                    : "Tweet {$n} is {$over} {$chars} over X's {$limit} limit.";
            }
        }

        return $errors;
    }

    /**
     * Over-limit check for a single-caption platform (everything except X,
     * where xThreadErrors applies). Null means the caption fits.
     */
    public static function captionError(string $platform, string $text): ?string
    {
        $limit = self::limit($platform);
        if ($limit === null) {
            return null;
        }

        $over = self::plainLength($text) - $limit;
        if ($over <= 0) {
            return null;
        }

        $chars = $over === 1 ? 'character' : 'characters';
        $label = self::PLATFORM_LABELS[$platform] ?? ucfirst($platform);

        return "Caption is {$over} {$chars} over {$label}'s {$limit} limit.";
    }

    /** Display names for validation messages. */
    public const PLATFORM_LABELS = [
        'youtube' => 'YouTube',
        'tiktok' => 'TikTok',
        'instagram' => 'Instagram',
        'x' => 'X',
        'linkedin' => 'LinkedIn',
        'facebook' => 'Facebook',
        'threads' => 'Threads',
        'bluesky' => 'Bluesky',
    ];

    private static function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
