<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\TrackingEventController;
use App\Http\Controllers\TrackingLinkController;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('List offers')]
#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ListOffers extends ViewsMaxTool
{
    private const DEFAULT_LIMIT = 25;

    private const MAX_LIMIT = 100;

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
            . 'goals, and per-offer click/conversion stats. Optional from/to date filter. '
            . 'Returns at most `limit` offers (default ' . self::DEFAULT_LIMIT . ').';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('from')->description('Earliest created_at (ISO-8601 date).')->optional()
            ->string('to')->description('Latest created_at (ISO-8601 date).')->optional()
            ->integer('limit')->description('Max offers to return (1-' . self::MAX_LIMIT . ', default ' . self::DEFAULT_LIMIT . ').')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        $validated = Validator::validate($arguments, [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
        ]);

        $limit = $validated['limit'] ?? self::DEFAULT_LIMIT;

        // The controller has no limit concept (the web app lists everything),
        // so trim afterwards to keep tool responses small.
        return $this->callController(
            fn ($request) => app(TrackingEventController::class)->index($request),
            array_intersect_key($validated, array_flip(['from', 'to'])),
            fn (array $data) => ['data' => collect($data['data'] ?? [])
                ->take($limit)
                // Offers arrive as models; decode them the way the response
                // would serialize them before adding each link's URL.
                ->map(fn ($offer) => self::withTrackedUrls(json_decode(json_encode($offer), true)))
                ->values()
                ->all()]
        );
    }
}
