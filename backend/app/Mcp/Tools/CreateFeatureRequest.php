<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\FeatureRequestController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class CreateFeatureRequest extends ViewsMaxTool
{
    public function name(): string
    {
        return 'create_feature_request';
    }

    public function description(): string
    {
        return 'Submit a feature request to the ViewsMax team on the user\'s behalf.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('title')->description('Short title.')
            ->string('description')->description('What the user wants and why.')
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
