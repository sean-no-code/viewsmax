<?php

namespace Tests\Unit;

use App\Models\Automation;
use App\Services\Automations\KeywordMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Pure matching logic: keyword normalization/modes, post filter, match
 * priority and CTR. No database.
 */
class AutomationMatchingTest extends TestCase
{
    public function test_any_mode_matches_everything_including_empty_text(): void
    {
        $this->assertSame('*', KeywordMatcher::match(Automation::KEYWORD_ANY, [], ''));
        $this->assertSame('*', KeywordMatcher::match(Automation::KEYWORD_ANY, [], null));
    }

    public function test_contains_is_case_whitespace_and_unicode_insensitive(): void
    {
        $this->assertSame('link', KeywordMatcher::match(Automation::KEYWORD_CONTAINS, ['Link'], "  Send me the LINK\u{00A0}please "));
        // Zero-width chars inside the word don't defeat the match.
        $this->assertSame('link', KeywordMatcher::match(Automation::KEYWORD_CONTAINS, ['link'], "li\u{200B}nk"));
        // Emoji keywords work.
        $this->assertSame('🔥', KeywordMatcher::match(Automation::KEYWORD_CONTAINS, ['🔥'], 'this is 🔥🔥'));
        $this->assertNull(KeywordMatcher::match(Automation::KEYWORD_CONTAINS, ['price'], 'send me the link'));
        $this->assertNull(KeywordMatcher::match(Automation::KEYWORD_CONTAINS, ['price'], ''));
    }

    public function test_exact_mode_requires_the_whole_text(): void
    {
        $this->assertSame('link', KeywordMatcher::match(Automation::KEYWORD_EXACT, ['link'], ' LINK '));
        $this->assertNull(KeywordMatcher::match(Automation::KEYWORD_EXACT, ['link'], 'the link please'));
    }

    public function test_first_matching_keyword_is_returned_and_list_is_normalized(): void
    {
        $this->assertSame(['price', 'link'], KeywordMatcher::normalizeList([' Price ', 'LINK', '', 'price', 42]));
        $this->assertSame('link', KeywordMatcher::match(Automation::KEYWORD_CONTAINS, ['price', 'link'], 'link?'));
    }

    public function test_media_filter_only_applies_to_specific_comment_triggers(): void
    {
        $specific = new Automation([
            'trigger_type' => Automation::TRIGGER_COMMENT,
            'post_match' => Automation::POST_MATCH_SPECIFIC,
            'posts' => [['id' => '111', 'thumbnail_url' => 'x'], ['id' => 222]],
        ]);
        $this->assertSame(['111', '222'], $specific->mediaIds());
        $this->assertTrue($specific->matchesMedia('111'));
        $this->assertTrue($specific->matchesMedia('222'));
        $this->assertFalse($specific->matchesMedia('333'));
        $this->assertFalse($specific->matchesMedia(null));

        $any = new Automation(['trigger_type' => Automation::TRIGGER_COMMENT, 'post_match' => Automation::POST_MATCH_ANY]);
        $this->assertTrue($any->matchesMedia('999'));

        $dm = new Automation(['trigger_type' => Automation::TRIGGER_DM, 'post_match' => Automation::POST_MATCH_SPECIFIC]);
        $this->assertTrue($dm->matchesMedia(null));
    }

    public function test_specificity_orders_specific_post_and_keywords_first(): void
    {
        $make = fn (string $trigger, ?string $postMatch, string $keywordMode) => (new Automation([
            'trigger_type' => $trigger, 'post_match' => $postMatch, 'keyword_mode' => $keywordMode,
        ]))->specificity();

        $this->assertSame(3, $make(Automation::TRIGGER_COMMENT, 'specific', 'contains'));
        $this->assertSame(2, $make(Automation::TRIGGER_COMMENT, 'specific', 'any'));
        $this->assertSame(1, $make(Automation::TRIGGER_COMMENT, 'any', 'contains'));
        $this->assertSame(0, $make(Automation::TRIGGER_COMMENT, 'any', 'any'));
        $this->assertSame(1, $make(Automation::TRIGGER_DM, null, 'exact'));
    }

    public function test_ctr_and_button_helpers(): void
    {
        $a = new Automation(['dm_text' => 'hi']);
        $a->dms_sent_count = 0;
        $a->clicked_count = 0;
        $this->assertNull($a->ctr());
        $this->assertFalse($a->hasButton());

        $a->dms_sent_count = 4;
        $a->clicked_count = 1;
        $a->dm_button_url = 'https://example.com';
        $a->dm_button_label = 'Go';
        $this->assertSame(0.25, $a->ctr());
        $this->assertTrue($a->hasButton());
    }

    public function test_pick_reply_text_returns_one_of_the_configured_texts(): void
    {
        $a = new Automation(['reply_texts' => [' Sent you a DM ', '', 'Check your inbox']]);
        $this->assertContains($a->pickReplyText(), ['Sent you a DM', 'Check your inbox']);
        $this->assertNull((new Automation(['reply_texts' => null]))->pickReplyText());
    }

    public function test_trigger_summary_reads_like_the_index_row(): void
    {
        $this->assertSame(
            'User comments on a specific Post or Reel and comment contains price, link',
            (new Automation(['trigger_type' => 'comment', 'post_match' => 'specific', 'keyword_mode' => 'contains', 'keywords' => ['price', 'link']]))->triggerSummary()
        );
        $this->assertSame('User comments on any Post or Reel', (new Automation(['trigger_type' => 'comment', 'post_match' => 'any', 'keyword_mode' => 'any']))->triggerSummary());
        $this->assertSame('User replies to any Story and message is exactly info', (new Automation(['trigger_type' => 'story_reply', 'keyword_mode' => 'exact', 'keywords' => ['info']]))->triggerSummary());
        $this->assertSame('User sends a DM', (new Automation(['trigger_type' => 'dm', 'keyword_mode' => 'any']))->triggerSummary());
    }
}
