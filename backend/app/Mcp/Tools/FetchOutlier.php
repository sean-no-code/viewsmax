<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\OutlierController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class FetchOutlier extends ViewsMaxTool
{
    public function name(): string
    {
        return 'fetch_outlier';
    }

    public function description(): string
    {
        return 'Pull a specific video into the outlier database from its URL so it can be '
            . 'analysed (get_outlier, generate_outlier_breakdown, save_outlier). If the video '
            . 'is already known it is returned immediately; otherwise ingestion is queued '
            . '(`queued: true`) — poll get_outlier with the returned platform + video_id.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('platform')->description('youtube, tiktok, or instagram.')->required()
            ->string('url')->description('Public URL of the video on that platform.')->required();
    }

    public function handle(array $arguments): ToolResult
    {
        if ($limited = $this->hourlyLimit('fetch_outlier')) {
            return $limited;
        }

        $validated = Validator::validate($arguments, [
            'platform' => 'required|string|in:youtube,tiktok,instagram',
            'url' => 'required|url|max:2048',
        ]);

        return $this->callController(
            fn (Request $request) => app(OutlierController::class)->fetchByUrl($request),
            $validated,
            fn (array $data) => [
                'queued' => (bool) ($data['queued'] ?? false),
                'platform' => $data['platform'] ?? $validated['platform'],
                'video_id' => $data['video_id'] ?? null,
                'outlier' => isset($data['data']) ? $this->serializeOutlier((array) $data['data']) : null,
            ]
        );
    }
}
