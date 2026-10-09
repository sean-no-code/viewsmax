<?php

namespace App\Http\Middleware;

use App\Mcp\Methods\SafeCallTool;
use App\Models\McpToolInvocation;
use Closure;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Audit-logs every MCP tools/call after it completes: the authenticated
 * user, tool name, arguments, and whether (and why) the tool returned an
 * error. Runs
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
                $error = $this->errorMessage($response);

                McpToolInvocation::create([
                    'user_id' => $request->user()->id,
                    'tool' => (string) $request->input('params.name'),
                    'arguments' => $request->input('params.arguments'),
                    'is_error' => $error !== null,
                    'error' => $error,
                    'auth_mode' => $request->attributes->get('mcp_auth_mode'),
                    // Set by SafeCallTool after a successful, metered call.
                    'credits_charged' => $request->attributes->get(SafeCallTool::CHARGED_ATTRIBUTE),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $response;
    }

    /**
     * The error text when the call failed, null when it succeeded. A tool
     * failure is either a tool-level error (result.isError, per MCP — the
     * text content carries the message) or a JSON-RPC protocol error
     * (top-level error key). Capped so a runaway message can't bloat the log.
     */
    private function errorMessage(SymfonyResponse $response): ?string
    {
        $body = json_decode((string) $response->getContent(), true);

        if (! is_array($body)) {
            return null;
        }

        if (isset($body['error'])) {
            $message = $body['error']['message'] ?? json_encode($body['error']);

            return Str::limit((string) $message, 1000, '');
        }

        if (! ($body['result']['isError'] ?? false)) {
            return null;
        }

        $text = collect($body['result']['content'] ?? [])->firstWhere('type', 'text')['text'] ?? '';

        return Str::limit((string) $text, 1000, '');
    }
}
