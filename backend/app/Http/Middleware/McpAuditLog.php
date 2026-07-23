<?php

namespace App\Http\Middleware;

use App\Models\McpToolInvocation;
use Closure;
use Illuminate\Http\Request as LaravelRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Audit-logs every MCP tools/call after it completes: the authenticated
 * user, tool name, arguments, and whether the tool returned an error. Runs
 * after McpAuth so the user is resolved. Logging failures are reported but
 * never break the tool call itself.
 */
class McpAuditLog
{
    public function handle(LaravelRequest $request, Closure $next): SymfonyResponse
    {
        $response = $next($request);

        if ($request->input('method') === 'tools/call' && $request->user()) {
            try {
                McpToolInvocation::create([
                    'user_id' => $request->user()->id,
                    'tool' => (string) $request->input('params.name'),
                    'arguments' => $request->input('params.arguments'),
                    'is_error' => $this->responseIndicatesError($response),
                    'auth_mode' => $request->attributes->get('mcp_auth_mode'),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $response;
    }

    /**
     * A tool failure is either a tool-level error (result.isError, per MCP)
     * or a JSON-RPC protocol error (top-level error key).
     */
    private function responseIndicatesError(SymfonyResponse $response): bool
    {
        $body = json_decode((string) $response->getContent(), true);

        return (bool) ($body['result']['isError'] ?? isset($body['error']));
    }
}
