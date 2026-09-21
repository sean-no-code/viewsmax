<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request as LaravelRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * MCP Streamable HTTP: when the client posts a notification (or a response),
 * "the server MUST return HTTP status code 202 Accepted with no body".
 * laravel/mcp answers those with an empty 200 instead, which Codex's client
 * treats as a broken connection and reconnects in a loop.
 */
class McpAcceptNotifications
{
    public function handle(LaravelRequest $request, Closure $next): SymfonyResponse
    {
        $response = $next($request);

        $message = json_decode($request->getContent(), true);

        if (! is_array($message)) {
            return $response;
        }

        $isNotification = isset($message['method']) && ! array_key_exists('id', $message);
        $isClientResponse = ! isset($message['method'])
            && (array_key_exists('result', $message) || array_key_exists('error', $message));

        if (($isNotification || $isClientResponse)
            && $response->getStatusCode() === 200
            && $response->getContent() === '') {
            return response('', 202);
        }

        return $response;
    }
}
