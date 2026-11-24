<?php

namespace App\Http\Middleware;

use App\Support\UserAccess;
use Closure;
use Illuminate\Http\Request as LaravelRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * MCP delivery of UserAccess for denials that paying fixes (subscription
 * ended, free trial over, no card added): every tools/call answers HTTP 402
 * with a JSON-RPC error whose `data` is the denial (`code`, `message`,
 * `upgrade_url`), and the tool never runs. initialize, tools/list and prompts
 * pass through so the connector stays attached.
 *
 * A middleware because laravel/mcp's HTTP transport always answers 200 and its
 * server swallows exceptions, so a tools/call handler can't set a status. Runs
 * after McpAuth (the user is resolved) and inside McpAuditLog, which records
 * the refusal as a failed call. Other denials (an unverified email) fall
 * through to SafeCallTool.
 */
class EnsureMcpAccess
{
    /** JSON-RPC's implementation-defined server error. */
    private const JSON_RPC_SERVER_ERROR = -32000;

    public function handle(LaravelRequest $request, Closure $next): SymfonyResponse
    {
        if ($request->input('method') !== 'tools/call' || ! ($user = $request->user())) {
            return $next($request);
        }

        $denial = UserAccess::denial($user);

        if (! $denial || $denial->status() !== 402) {
            return $next($request);
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $request->input('id'),
            'error' => [
                'code' => self::JSON_RPC_SERVER_ERROR,
                'message' => $denial->message,
                'data' => $denial->error(),
            ],
        ], 402);
    }
}
