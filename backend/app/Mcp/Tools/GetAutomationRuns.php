<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\AutomationController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class GetAutomationRuns extends ViewsMaxTool
{
    public function name(): string
    {
        return 'get_automation_runs';
    }

    public function description(): string
    {
        return 'Recent runs of an automation, newest first: who triggered it, matched keyword, reply / DM status, whether the tracked link was clicked. Paginated (page).';
    }

    protected function requiresWrite(): bool
    {
        return false;
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('id')->description('Automation id.')
            ->integer('page')->description('Page number (25 per page).')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        $id = (int) ($arguments['id'] ?? 0);
        unset($arguments['id']);

        return $this->callController(
            fn ($request) => app(AutomationController::class)->runs($request, $id),
            $arguments
        );
    }
}
