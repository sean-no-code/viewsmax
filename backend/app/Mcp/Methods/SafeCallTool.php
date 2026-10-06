<?php

namespace App\Mcp\Methods;

use App\Mcp\Tools\ViewsMaxTool;
use App\Models\User;
use App\Support\UserSafeError;
use Bavix\Wallet\Exceptions\BalanceIsEmpty;
use Bavix\Wallet\Exceptions\InsufficientFunds;
use Generator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolResult;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use Throwable;

/**
 * laravel/mcp's tools/call handler, plus credit metering and a safety net
 * for unexpected crashes.
 *
 * Metering: every tool declares a credit cost (ViewsMaxTool::creditCost,
 * from config/credits.php `mcp`). The call is refused before it runs when
 * the user's balance is below the cost, and the cost is withdrawn only after
 * the tool returns a non-error result — so a failed call is free and the
 * balance never goes negative. The amount charged is handed to McpAuditLog
 * through the `mcp_credits_charged` request attribute, and each charge or
 * refusal is written to the `credits` log channel.
 *
 * Crash safety: out of the box, an exception thrown inside a tool escapes to
 * the server, which sends the raw exception message (class and property
 * names included) back as a JSON-RPC protocol error. Instead, log the
 * details with a correlation id and return a normal tool result with
 * isError: true and a plain-language message:
 *  - MCP spec, Tools → Error Handling: tool failures are "Tool Execution
 *    Errors: Reported in tool results with isError: true".
 *  - Anthropic Directory Policy 5A: "gracefully handle errors and provide
 *    helpful feedback".
 *  - OpenAI plugin guidelines: "Errors, including unexpected ones, must be
 *    handled with clear messaging"; submission: no debug payloads or internal
 *    identifiers in tool responses.
 *
 * Messages that provider services mark as user-safe (see UserSafeError) are
 * passed through unchanged.
 */
class SafeCallTool extends CallTool
{
    public const CHARGED_ATTRIBUTE = 'mcp_credits_charged';

    public function handle(JsonRpcRequest $request, ServerContext $context)
    {
        $name = (string) ($request->params['name'] ?? '');
        // Resolve once: tools() instantiates every registered tool per call.
        $tool = $context->tools()->first(fn (Tool $tool) => $tool->name() === $name);
        $user = request()->user();
        $cost = ($tool instanceof ViewsMaxTool && $user instanceof User) ? $tool->creditCost() : 0;

        if ($cost > 0 && $user->balanceInt < $cost) {
            Log::channel('credits')->info('MCP tool refused: insufficient credits', [
                'user_id' => $user->id,
                'tool' => $name,
                'cost' => $cost,
                'balance' => $user->balanceInt,
            ]);

            return JsonRpcResponse::create($request->id, ToolResult::error(sprintf(
                'Not enough credits to run %s (%d needed, %d available). '
                . 'Credits are managed in the ViewsMax web app (%s).',
                $name,
                $cost,
                $user->balanceInt,
                config('mcp.frontend_url'),
            )));
        }

        try {
            $response = parent::handle($request, $context);
        } catch (Throwable $e) {
            Log::error('MCP tool call failed', [
                'tool' => $name,
                'user_id' => $user?->id,
                'correlation_id' => (string) Str::uuid(),
                'exception' => $e,
            ]);

            return JsonRpcResponse::create(
                $request->id,
                ToolResult::error(UserSafeError::message($e, $this->fallbackMessage($name, $tool)))
            );
        }

        if ($response instanceof Generator) {
            return $response; // streamed tools aren't metered (none exist today)
        }

        if ($cost > 0 && ! ($response->result['isError'] ?? false)) {
            $this->charge($user, $name, $cost);
        }

        return $response;
    }

    /**
     * Withdraw the cost after a successful call. A concurrent call may have
     * drained the balance between the pre-check and here; the work is already
     * done, so skip the charge rather than go negative or fail the result.
     */
    private function charge(User $user, string $name, int $cost): void
    {
        try {
            $user->withdraw($cost, [
                'description' => "MCP tool: {$name}",
                'type' => 'mcp_tool',
                'tool' => $name,
            ]);
        } catch (InsufficientFunds|BalanceIsEmpty) {
            Log::channel('credits')->warning('MCP charge skipped: balance changed mid-call', [
                'user_id' => $user->id,
                'tool' => $name,
                'cost' => $cost,
                'balance' => $user->balanceInt,
            ]);

            return;
        }

        request()->attributes->set(self::CHARGED_ATTRIBUTE, $cost);

        Log::channel('credits')->info('MCP tool charged', [
            'user_id' => $user->id,
            'tool' => $name,
            'cost' => $cost,
            'balance_after' => $user->balanceInt,
        ]);
    }

    private function fallbackMessage(string $name, ?Tool $tool): string
    {
        $title = $tool?->annotations()['title'] ?? null;
        $action = $title ? lcfirst($title) : "run {$name}";

        return "Couldn't {$action} because of an unexpected problem on ViewsMax's side. Please try again shortly.";
    }
}
