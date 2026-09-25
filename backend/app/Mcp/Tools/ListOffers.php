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
            . 'Returns one page at a time (' . self::PAGE_SIZE . ' by default); when has_more is true, '
            . 'ask for the next page.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('from')->description('Earliest created_at (ISO-8601 date).')->optional()
            ->string('to')->description('Latest created_at (ISO-8601 date).')->optional()
            ->integer('limit')->description('Offers per page (1-' . self::MAX_PAGE_SIZE . ', default ' . self::PAGE_SIZE . ').')->optional()
            ->integer('page')->description('Page number, starting at 1. Check has_more in the reply for further pages.')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        $validated = Validator::validate($arguments, [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            ...self::pageRules(),
        ]);

        // The controller has no limit concept (the web app lists everything),
        // so page afterwards to keep tool responses small. Offers arrive as
        // models; decode them the way the response would serialize them
        // before adding each link's URL.
        return $this->callController(
            fn ($request) => app(TrackingEventController::class)->index($request),
            array_intersect_key($validated, array_flip(['from', 'to'])),
            fn (array $data) => self::pageOf(
                $data['data'] ?? [],
                $validated,
                'data',
                fn ($offer) => self::withTrackedUrls(json_decode(json_encode($offer), true))
            )
        );
    }
}
