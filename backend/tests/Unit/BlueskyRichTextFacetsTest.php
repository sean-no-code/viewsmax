<?php

namespace Tests\Unit;

use App\Services\Social\Bluesky\RichTextFacets;
use PHPUnit\Framework\TestCase;

/**
 * Facet detection for Bluesky: URLs, @handles and #tags with
 * UTF-8 byte offsets, mirroring @atproto/api detectFacets.
 */
class BlueskyRichTextFacetsTest extends TestCase
{
    private function detect(string $text, array $dids = []): array
    {
        return RichTextFacets::detect($text, fn (string $handle) => $dids[$handle] ?? null);
    }

    /** The bytes a facet covers, to prove the offsets point at the right text. */
    private function slice(string $text, array $facet): string
    {
        return substr($text, $facet['index']['byteStart'], $facet['index']['byteEnd'] - $facet['index']['byteStart']);
    }

    public function test_offsets_are_utf8_bytes_not_characters(): void
    {
        // "héllo " is 6 characters but 7 bytes, so every offset after it shifts by one.
        $text = 'héllo @alice.bsky.social https://example.com/x #Tag';
        $facets = $this->detect($text, ['alice.bsky.social' => 'did:plc:alice']);

        $this->assertCount(3, $facets);
        [$mention, $link, $tag] = $facets;

        $this->assertSame(['byteStart' => 7, 'byteEnd' => 25], $mention['index']);
        $this->assertSame('@alice.bsky.social', $this->slice($text, $mention));
        $this->assertSame([['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:alice']], $mention['features']);

        $this->assertSame('https://example.com/x', $this->slice($text, $link));
        $this->assertSame([['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com/x']], $link['features']);

        $this->assertSame('#Tag', $this->slice($text, $tag));
        $this->assertSame([['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'Tag']], $tag['features']);
    }

    public function test_unresolved_handles_stay_plain_text(): void
    {
        $facets = $this->detect('hi @ghost.bsky.social and @alice.bsky.social', ['alice.bsky.social' => 'did:plc:alice']);

        $this->assertCount(1, $facets);
        $this->assertSame('did:plc:alice', $facets[0]['features'][0]['did']);
    }

    public function test_things_that_are_not_handles_are_never_resolved(): void
    {
        $asked = [];
        RichTextFacets::detect('email me@home or @everyone or @no-tld', function (string $h) use (&$asked) {
            $asked[] = $h;

            return null;
        });

        $this->assertSame([], $asked);
    }

    public function test_links_drop_trailing_punctuation_and_closing_parens(): void
    {
        $text = 'read https://example.com/a. and (bsky.app) or www.example.org/path, ok';
        $facets = $this->detect($text);

        $this->assertSame(
            ['https://example.com/a', 'https://bsky.app', 'https://www.example.org/path'],
            array_map(fn ($f) => $f['features'][0]['uri'], $facets)
        );
        $this->assertSame('https://example.com/a', $this->slice($text, $facets[0]));
        $this->assertSame('bsky.app', $this->slice($text, $facets[1]));
        $this->assertSame('www.example.org/path', $this->slice($text, $facets[2]));
    }

    public function test_abbreviations_and_numbers_are_not_links_or_tags(): void
    {
        $this->assertSame([], $this->detect('e.g. version 1.2 costs #123 at 3.14'));
    }

    public function test_tags_handle_unicode_fullwidth_hash_and_trailing_punctuation(): void
    {
        $text = '#日本語! ＃full #'.str_repeat('x', 70);
        $facets = $this->detect($text);

        $this->assertSame(['日本語', 'full'], array_map(fn ($f) => $f['features'][0]['tag'], $facets));
        $this->assertSame('#日本語', $this->slice($text, $facets[0]));
        $this->assertSame('＃full', $this->slice($text, $facets[1]));
    }

    public function test_plain_text_has_no_facets(): void
    {
        $this->assertSame([], $this->detect('just words, nothing to link'));
    }
}
