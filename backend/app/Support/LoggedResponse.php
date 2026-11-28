<?php

namespace App\Support;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response body as the audit logs store it (mcp_tool_invocations.response,
 * api_key_requests.response): the text the client received, capped so one
 * large listing can't bloat the table. Null when there is no text to keep —
 * a streamed or file response, or a binary body.
 */
final class LoggedResponse
{
    public const LIMIT = 65535;

    public static function body(Response $response): ?string
    {
        $content = $response->getContent();

        if (! is_string($content) || $content === '' || ! mb_check_encoding($content, 'UTF-8')) {
            return null;
        }

        return Str::limit($content, self::LIMIT, ' …[truncated]');
    }
}
