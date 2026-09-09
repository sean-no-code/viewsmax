<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\AutomationController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class DeleteAutomation extends ViewsMaxTool
{
    public function name(): string
    {
        return 'delete_automation';
    }

    public function description(): string
    {
        return 'Delete an automation (soft delete; its runs are kept).';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->integer('id')->description('Automation id.');
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->callController(
            fn () => app(AutomationController::class)->destroy((int) ($arguments['id'] ?? 0)),
            []
        );
    }
}
