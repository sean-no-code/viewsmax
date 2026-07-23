<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\TrackingEventController;
use App\Http\Controllers\TrackingLinkController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class GetOfferStats extends ViewsMaxTool
{
    protected function requiresWrite(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'get_offer_stats';
    }

    public function description(): string
    {
        return 'Aggregated analytics across the user\'s offers: video views, clicks, '
            . 'calls booked, email signups, and sales revenue. Optional from/to window.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('from')->description('Window start (ISO-8601 date).')->optional()
            ->string('to')->description('Window end (ISO-8601 date).')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->callController(
            fn ($request) => app(TrackingEventController::class)->getStats($request),
            $arguments
        );
    }
}
