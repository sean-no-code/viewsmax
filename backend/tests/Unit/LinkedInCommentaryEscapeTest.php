<?php

namespace Tests\Unit;

use App\Services\Social\Providers\LinkedInProvider;
use PHPUnit\Framework\TestCase;

/**
 * LinkedIn's commentary format treats ( ) [ ] { } < > # * _ ~ @ | and
 * backslash as markup, so they are backslash-escaped — except inside
 * organization mentions, which are LinkedIn's own syntax.
 */
class LinkedInCommentaryEscapeTest extends TestCase
{
    public function test_every_markup_character_is_escaped_exactly_once(): void
    {
        $this->assertSame(
            'Hi \(there\) \[a\] \{b\} \<c\> \#tag \*bold\* \_it\_ \~x\~ \@you \| back\\\\slash',
            LinkedInProvider::escapeCommentary('Hi (there) [a] {b} <c> #tag *bold* _it_ ~x~ @you | back\slash')
        );
    }

    public function test_plain_text_is_unchanged(): void
    {
        $this->assertSame('Hello world, 100% fine: yes/no & more!', LinkedInProvider::escapeCommentary('Hello world, 100% fine: yes/no & more!'));
        $this->assertSame('', LinkedInProvider::escapeCommentary(''));
    }

    public function test_organization_mentions_pass_through_untouched(): void
    {
        $this->assertSame(
            'Thanks @[Acme Corp](urn:li:organization:123) for \(everything\) \#grateful @[Beta](urn:li:organization:9)!',
            LinkedInProvider::escapeCommentary('Thanks @[Acme Corp](urn:li:organization:123) for (everything) #grateful @[Beta](urn:li:organization:9)!')
        );
    }

    public function test_only_organization_mentions_are_protected(): void
    {
        // Only urn:li:organization mentions are exempt; anything else that
        // looks like a mention is ordinary text and gets escaped.
        $this->assertSame(
            '\@\[Jane\]\(urn:li:person:1\)',
            LinkedInProvider::escapeCommentary('@[Jane](urn:li:person:1)')
        );
    }
}
