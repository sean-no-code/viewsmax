<?php

namespace App\Mcp\Methods;

use App\Support\UserSafeError;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tools\ToolResult;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use Throwable;

/**
 * laravel/mcp's tools/call handler, plus a safety net for unexpected crashes.
 *
 * Out of the box, an exception thrown inside a tool escapes to the server,
 * which sends the raw exception message (class and property names included)
 * back as a JSON-RPC protocol error. Instead, log the details with a
 * correlation id and return a normal tool result with isError: true and a
 * plain-language message:
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
    public function handle(JsonRpcRequest $request, ServerContext $context)
    {
        try {
            return parent::handle($request, $context);
        } catch (Throwable $e) {
            $name = (string) ($request->params['name'] ?? '');

            Log::error('MCP tool call failed', [
                'tool' => $name,
                'user_id' => request()->user()?->id,
                'correlation_id' => (string) Str::uuid(),
                'exception' => $e,
            ]);

            return JsonRpcResponse::create(
                $request->id,
                ToolResult::error(UserSafeError::message($e, $this->fallbackMessage($name, $context)))
            );
        }
    }

    private function fallbackMessage(string $name, ServerContext $context): string
    {
        $tool = $context->tools()->first(fn ($tool) => $tool->name() === $name);
        $title = $tool?->annotations()['title'] ?? null;
        $action = $title ? lcfirst($title) : "run {$name}";

        return "Couldn't {$action} because of an unexpected problem on ViewsMax's side. Please try again shortly.";
    }
}
