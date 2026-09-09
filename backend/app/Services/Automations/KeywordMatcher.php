<?php

namespace App\Services\Automations;

use App\Models\Automation;

/**
 * Keyword matching for automation triggers. Both sides are normalized the
 * same way (lowercase, NFKC, zero-width chars stripped, whitespace collapsed)
 * so "  LINK please " matches "link" and curly quotes / NBSP in a comment
 * never defeat a match.
 */
class KeywordMatcher
{
    /** Matched-keyword marker for keyword_mode = any. */
    public const ANY = '*';

    public static function normalize(?string $text): string
    {
        $text = (string) $text;

        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        }

        // Zero-width space/joiners, BOM; NBSP → space.
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = mb_strtolower($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Normalize a user-entered keyword list: trimmed, lowercased, de-duped,
     * empties dropped.
     *
     * @param  array<int, mixed>  $keywords
     * @return array<int, string>
     */
    public static function normalizeList(array $keywords): array
    {
        $out = [];
        foreach ($keywords as $keyword) {
            $normalized = self::normalize(is_string($keyword) ? $keyword : '');
            if ($normalized !== '' && ! in_array($normalized, $out, true)) {
                $out[] = $normalized;
            }
        }

        return $out;
    }

    /**
     * The keyword that matched (or "*" for any), or null when nothing matched.
     *
     * @param  array<int, string>  $keywords
     */
    public static function match(string $mode, array $keywords, ?string $text): ?string
    {
        if ($mode === Automation::KEYWORD_ANY) {
            return self::ANY;
        }

        $haystack = self::normalize($text);
        if ($haystack === '') {
            return null;
        }

        foreach (self::normalizeList($keywords) as $keyword) {
            $hit = $mode === Automation::KEYWORD_EXACT
                ? $haystack === $keyword
                : str_contains($haystack, $keyword);

            if ($hit) {
                return $keyword;
            }
        }

        return null;
    }
}
