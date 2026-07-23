<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\TrackingEventController;
use App\Http\Controllers\TrackingLinkController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class CreateOffer extends ViewsMaxTool
{
    public function name(): string
    {
        return 'create_offer';
    }

    public function description(): string
    {
        return 'Create an offer (a promotion to track). Requires offer_url; optional '
            . 'name and goals (conversion events with a conversion_url and value). '
            . 'Subject to the user\'s plan offer limit.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('offer_url')->description('The landing page URL of the offer.')
            ->string('name')->description('Display name.')->optional()
            ->raw('goals', [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'event_type' => ['type' => 'string', 'description' => 'e.g. conversion, call booked, email-signup'],
                        'conversion_url' => ['type' => 'string'],
                        'conversion_value' => ['type' => 'number'],
                    ],
                    'required' => ['event_type', 'conversion_url'],
                ],
                'description' => 'Optional conversion goals.',
            ]);
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->callController(
            fn ($request) => app(TrackingEventController::class)->store($request),
            $arguments
        );
    }
}
