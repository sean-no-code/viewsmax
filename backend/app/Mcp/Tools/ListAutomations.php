<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\AutomationController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class ListAutomations extends ViewsMaxTool
{
    public function name(): string
    {
        return 'list_automations';
    }

    public function description(): string
    {
        return 'List the user\'s comment / story-reply / DM automations with run, DM-sent and click stats (CTR). Optional filters: trigger_type (comment, story_reply, dm), status (live, stopped), search.';
    }

    protected function requiresWrite(): bool
    {
        return false;
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('trigger_type')->description('Filter: comment, story_reply or dm.')->optional()
            ->string('status')->description('Filter: live or stopped.')->optional()
            ->string('search')->description('Name contains.')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->callController(
            fn ($request) => app(AutomationController::class)->index($request),
            $arguments
        );
    }
}
