<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\AutomationController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class StartAutomation extends ViewsMaxTool
{
    public function name(): string
    {
        return 'start_automation';
    }

    public function description(): string
    {
        return 'Set an automation live. Verifies the Instagram account still has the comments + messages permissions and subscribes it to the webhook.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->integer('id')->description('Automation id.');
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->callController(
            fn () => app(AutomationController::class)->start((int) ($arguments['id'] ?? 0)),
            []
        );
    }
}
