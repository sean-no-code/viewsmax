<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\FeatureRequestController;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Submit a feature request')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class CreateFeatureRequest extends ViewsMaxTool
{
    public function name(): string
    {
        return 'create_feature_request';
    }

    public function description(): string
    {
        return 'Submit a feature request to the ViewsMax team on the user\'s behalf. '
            . "Don't use this for plan, billing, or purchase requests; tell the user to manage "
            . 'their plan in the ViewsMax app instead. '
            . "Each call submits a new request, so don't repeat a call that already succeeded.";
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('title')->description('Short title.')->required()
            ->string('description')->description('What the user wants and why.')->required()
            ->string('category')->description('Optional category.')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->callController(
            fn ($request) => app(FeatureRequestController::class)->store($request),
            $arguments
        );
    }
}
