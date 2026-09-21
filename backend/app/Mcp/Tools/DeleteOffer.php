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

#[Title('Delete an offer')]
#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
class DeleteOffer extends ViewsMaxTool
{
    public function name(): string
    {
        return 'delete_offer';
    }

    public function description(): string
    {
        return 'Delete an offer and stop tracking it.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->integer('id')->description('The offer id.')->required();
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->callController(
            fn () => app(TrackingEventController::class)->destroy((string) ($arguments['id'] ?? '')),
        );
    }
}
