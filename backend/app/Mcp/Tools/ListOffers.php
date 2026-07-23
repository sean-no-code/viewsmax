<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\TrackingEventController;
use App\Http\Controllers\TrackingLinkController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class ListOffers extends ViewsMaxTool
{
    protected function requiresWrite(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'list_offers';
    }

    public function description(): string
    {
        return 'List the user\'s offers (tracked promotions) with their tracking links, '
            . 'goals, and per-offer click/conversion stats. Optional from/to date filter.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('from')->description('Earliest created_at (ISO-8601 date).')->optional()
            ->string('to')->description('Latest created_at (ISO-8601 date).')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->callController(
            fn ($request) => app(TrackingEventController::class)->index($request),
            $arguments
        );
    }
}
