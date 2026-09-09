<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\AutomationController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class StopAutomation extends ViewsMaxTool
{
    public function name(): string
    {
        return 'stop_automation';
    }

    public function description(): string
    {
        return 'Stop a live automation. In-flight runs are skipped.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->integer('id')->description('Automation id.');
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->callController(
            fn () => app(AutomationController::class)->stop((int) ($arguments['id'] ?? 0)),
            []
        );
    }
}
