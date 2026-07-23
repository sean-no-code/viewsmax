<?php

namespace Tests\Unit;

use App\Services\Social\CaptionRules;
use PHPUnit\Framework\TestCase;

class CaptionRulesTest extends TestCase
{
    /* ---------------- limits ---------------- */

    public function test_limit_per_platform(): void
    {
        $this->assertSame(280, CaptionRules::limit('x'));
        $this->assertSame(3000, CaptionRules::limit('linkedin'));
        $this->assertSame(500, CaptionRules::limit('threads'));
        $this->assertSame(2200, CaptionRules::limit('tiktok'));
        $this->assertNull(CaptionRules::limit('myspace'));
    }

    /* ---------------- plain length ---------------- */

    public function test_plain_length_counts_code_points(): void
    {
        $this->assertSame(5, CaptionRules::plainLength('hello'));
        $this->assertSame(0, CaptionRules::plainLength(''));
        // Emoji is one code point in plain counting (matches frontend [...t].length).
        $this->assertSame(1, CaptionRules::plainLength('😀'));
    }

    /* ---------------- t.co-aware X length ---------------- */

    public function test_x_length_counts_any_url_as_23(): void
    {
        $url = 'https://example.com/some/very/long/path/that/keeps/going/and/going/forever/2026';
        $this->assertGreaterThan(23, mb_strlen($url));
        $this->assertSame(23, CaptionRules::xLength($url));
    }

    public function test_x_length_counts_www_url_as_23(): void
    {
        $this->assertSame(23, CaptionRules::xLength('www.example.com/path'));
    }

    public function test_x_length_two_urls_plus_text(): void
    {
        // "a " (2) + url (23) + " b " (3) + url (23) = 51
        $text = 'a https://ex.com/one b www.ex.com/two';
        $this->assertSame(2 + 23 + 3 + 23, CaptionRules::xLength($text));
    }

    public function test_x_length_plain_ascii_unchanged(): void
    {
        $this->assertSame(11, CaptionRules::xLength('hello world'));
    }

    public function test_x_length_emoji_weighs_two(): void
    {
        $this->assertSame(2, CaptionRules::xLength('😀'));
    }

    public function test_x_length_cjk_weighs_two(): void
    {
        $this->assertSame(6, CaptionRules::xLength('日本語'));
    }

    public function test_x_length_newline_weighs_one_and_crlf_normalizes(): void
    {
        $this->assertSame(3, CaptionRules::xLength("a\nb"));
        $this->assertSame(3, CaptionRules::xLength("a\r\nb"));
    }

    /* ---------------- thread splitting ---------------- */

    public function test_split_no_delimiter_is_single_segment(): void
    {
        $this->assertSame(['just one tweet'], CaptionRules::splitXThread('just one tweet'));
    }

    public function test_split_empty_input_is_no_segments(): void
    {
        $this->assertSame([], CaptionRules::splitXThread(''));
        $this->assertSame([], CaptionRules::splitXThread("   \n  "));
    }

    public function test_split_three_segments(): void
    {
        $this->assertSame(['one', 'two', 'three'], CaptionRules::splitXThread("one\n---\ntwo\n---\nthree"));
    }

    public function test_split_tolerates_whitespace_around_delimiter(): void
    {
        $this->assertSame(['one', 'two'], CaptionRules::splitXThread("one\n  ---\t \ntwo"));
    }

    public function test_four_dashes_and_inline_dashes_are_literal(): void
    {
        $this->assertSame(["one\n----\ntwo"], CaptionRules::splitXThread("one\n----\ntwo"));
        $this->assertSame(['a --- b'], CaptionRules::splitXThread('a --- b'));
    }

    public function test_segments_are_trimmed(): void
    {
        $this->assertSame(['one', 'two'], CaptionRules::splitXThread("  one  \n---\n\ntwo\n"));
    }

    /* ---------------- thread validation ---------------- */

    public function test_thread_errors_empty_for_valid_thread(): void
    {
        $this->assertSame([], CaptionRules::xThreadErrors("one\n---\ntwo"));
        $this->assertSame([], CaptionRules::xThreadErrors(''));
    }

    public function test_single_over_limit_segment_suggests_splitting(): void
    {
        $errors = CaptionRules::xThreadErrors(str_repeat('a', 300));
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('20 characters over', $errors[0]);
        $this->assertStringContainsString('---', $errors[0]);
    }

    public function test_over_limit_thread_segment_reports_its_number(): void
    {
        $errors = CaptionRules::xThreadErrors("ok\n---\n".str_repeat('b', 281));
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Tweet 2', $errors[0]);
        $this->assertStringContainsString('1 character over', $errors[0]);
    }

    public function test_empty_segment_reports_its_number(): void
    {
        $errors = CaptionRules::xThreadErrors("a\n---\n---\nb");
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Tweet 2', $errors[0]);
        $this->assertStringContainsString('empty', $errors[0]);
    }

    public function test_trailing_delimiter_is_an_empty_segment(): void
    {
        $errors = CaptionRules::xThreadErrors("a\n---\n");
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Tweet 2', $errors[0]);
    }

    public function test_too_many_segments(): void
    {
        $text = implode("\n---\n", array_fill(0, 26, 'tweet'));
        $errors = CaptionRules::xThreadErrors($text);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('25', $errors[0]);
    }

    public function test_url_heavy_segment_passes_at_weighted_280(): void
    {
        // 257 chars + space + long URL = 257 + 1 + 22... make it exactly 280:
        // 256 plain + 1 space + 23 (url) = 280.
        $text = str_repeat('a', 256).' https://example.com/a/very/long/path/way/past/23/chars';
        $this->assertSame(280, CaptionRules::xLength($text));
        $this->assertSame([], CaptionRules::xThreadErrors($text));
    }

    /* ---------------- per-platform caption errors ---------------- */

    public function test_caption_error_null_when_under_limit(): void
    {
        $this->assertNull(CaptionRules::captionError('linkedin', str_repeat('a', 3000)));
    }

    public function test_caption_error_when_over_limit(): void
    {
        $error = CaptionRules::captionError('linkedin', str_repeat('a', 3001));
        $this->assertNotNull($error);
        $this->assertStringContainsString('1 character over', $error);
        $this->assertStringContainsString('3000', $error);
    }

    public function test_caption_error_unknown_platform_is_null(): void
    {
        $this->assertNull(CaptionRules::captionError('myspace', str_repeat('a', 99999)));
    }
}
