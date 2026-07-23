<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\TrackingEventController;
use App\Http\Controllers\TrackingLinkController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class GetOffer extends ViewsMaxTool
{
    protected function requiresWrite(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'get_offer';
    }

    public function description(): string
    {
        return 'Fetch one offer by id, with its tracking links and goals.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->integer('id')->description('The offer id.');
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->callController(
            fn () => app(TrackingEventController::class)->show((string) ($arguments['id'] ?? '')),
        );
    }
}
