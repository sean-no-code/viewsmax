<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\OutlierController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Get an outlier video')]
#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class GetOutlier extends ViewsMaxTool
{
    protected function requiresWrite(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'get_outlier';
    }

    public function description(): string
    {
        return 'Fetch one outlier video by platform + video id (as returned by list_outliers '
            . 'or fetch_outlier), including its channel, views, outlier score and engagement.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('platform')->description('youtube, tiktok, or instagram.')->required()
            ->string('video_id')->description('The platform\'s video id.')->required();
    }

    public function handle(array $arguments): ToolResult
    {
        $validated = Validator::validate($arguments, [
            'platform' => 'required|string|in:youtube,tiktok,instagram',
            'video_id' => 'required|string|max:255',
        ]);

        return $this->callController(
            fn (Request $request) => app(OutlierController::class)->show($request, $validated['platform'], $validated['video_id']),
            [],
            fn (array $data) => $this->serializeOutlier((array) ($data['data'] ?? []))
        );
    }
}
