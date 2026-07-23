<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\TrackingEventController;
use App\Http\Controllers\TrackingLinkController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class GetStatsTimeseries extends ViewsMaxTool
{
    protected function requiresWrite(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'get_stats_timeseries';
    }

    public function description(): string
    {
        return 'Daily time-series of clicks and attributed revenue (one bucket per day, '
            . 'gaps zero-filled). Defaults to the last 28 days; filter with from/to '
            . 'and optionally a single offer via event_id.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('from')->description('Window start (ISO-8601 date).')->optional()
            ->string('to')->description('Window end (ISO-8601 date).')->optional()
            ->integer('event_id')->description('Limit to one offer id.')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->callController(
            fn ($request) => app(TrackingEventController::class)->getTimeseries($request),
            $arguments
        );
    }
}
