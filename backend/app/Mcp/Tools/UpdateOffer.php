<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\TrackingEventController;
use App\Http\Controllers\TrackingLinkController;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Update an offer')]
#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
class UpdateOffer extends ViewsMaxTool
{
    public function name(): string
    {
        return 'update_offer';
    }

    public function description(): string
    {
        return 'Update an offer\'s name, offer_url, goals, or links.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('id')->description('The offer id.')->required()
            ->string('name')->description('New display name.')->optional()
            ->string('offer_url')->description('New landing page URL.')->optional()
            ->raw('goals', [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'event_type' => ['type' => 'string'],
                        'conversion_url' => ['type' => 'string'],
                        'conversion_value' => ['type' => 'number'],
                    ],
                    'required' => ['event_type', 'conversion_url'],
                ],
                'description' => 'Replaces the goal list when provided.',
            ]);
    }

    public function handle(array $arguments): ToolResult
    {
        $id = (string) ($arguments['id'] ?? '');
        unset($arguments['id']);

        return $this->callController(
            fn ($request) => app(TrackingEventController::class)->update($request, $id),
            $arguments
        );
    }
}
