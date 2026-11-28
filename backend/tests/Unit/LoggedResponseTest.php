<?php

namespace Tests\Unit;

use App\Support\LoggedResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The response body the audit logs keep: text only, capped. */
class LoggedResponseTest extends TestCase
{
    public function test_text_bodies_are_kept_as_sent(): void
    {
        $this->assertSame('{"ok":true}', LoggedResponse::body(new Response('{"ok":true}')));
    }

    public function test_long_bodies_are_capped_and_marked(): void
    {
        $body = LoggedResponse::body(new Response(str_repeat('a', LoggedResponse::LIMIT + 500)));

        $this->assertStringStartsWith(str_repeat('a', LoggedResponse::LIMIT), $body);
        $this->assertStringEndsWith('[truncated]', $body);
        $this->assertLessThan(LoggedResponse::LIMIT + 20, mb_strlen($body));
    }

    public function test_empty_streamed_and_binary_bodies_are_not_kept(): void
    {
        $this->assertNull(LoggedResponse::body(new Response('')));
        $this->assertNull(LoggedResponse::body(new StreamedResponse(fn () => print 'x')));
        $this->assertNull(LoggedResponse::body(new Response("\xFF\xD8\xFF\xE0")));
    }
}
