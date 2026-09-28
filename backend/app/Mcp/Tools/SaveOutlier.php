<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\SavedOutlierController;
use App\Models\OutlierVideo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Save an outlier video')]
#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
class SaveOutlier extends ViewsMaxTool
{
    public function name(): string
    {
        return 'save_outlier';
    }

    public function description(): string
    {
        return 'Bookmark an outlier video into the user\'s library, optionally with tags '
            . '(created on demand). Saving the same video again replaces its tags. The video '
            . 'must already be in the outlier database (list_outliers / fetch_outlier).';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('platform')->description('youtube, tiktok, or instagram.')->required()
            ->string('video_id')->description('The platform\'s video id.')->required()
            ->raw('tags', ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tag names, e.g. ["hooks", "q3-ideas"].'])->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        $validated = Validator::validate($arguments, [
            'platform' => 'required|string|in:youtube,tiktok,instagram',
            'video_id' => 'required|string|max:255',
            'tags' => 'nullable|array|max:50',
            'tags.*' => 'string|max:50',
        ]);

        $video = OutlierVideo::with('channel')
            ->where('platform', $validated['platform'])
            ->where('youtube_video_id', $validated['video_id'])
            ->first();

        if (! $video) {
            return ToolResult::error(
                "Video {$validated['video_id']} is not in the outlier database yet — call fetch_outlier with its URL first."
            );
        }

        // Same snapshot the web app stores, so the library renders identically.
        $snapshot = [
            'title' => $video->title,
            'thumbnail_url' => $video->thumbnail_url,
            'thumbnail_medium_url' => $video->thumbnail_medium_url,
            'views' => $video->views,
            'like_count' => $video->like_count,
            'comment_count' => $video->comment_count,
            'outlier_score' => $video->outlier_score,
            'engagement_rate' => $video->engagement_rate,
            'duration' => $video->duration,
            'published_at' => $video->published_at?->toIso8601String(),
            'channel_name' => $video->channel?->channel_name,
            'channel_avatar' => $video->channel?->profile_image_url,
            'subscriber_count' => $video->channel?->subscriber_count,
            'channel_average_views' => $video->channel?->average_views,
            'platform' => $video->platform,
        ];

        $params = [
            'platform' => $validated['platform'],
            'video_id' => $validated['video_id'],
            'snapshot' => $snapshot,
        ];
        if (array_key_exists('tags', $validated) && $validated['tags'] !== null) {
            $params['tags'] = $validated['tags'];
        }

        return $this->callController(
            fn (Request $request) => app(SavedOutlierController::class)->store($request),
            $params,
            fn (array $data) => ListSavedOutliers::serializeSaved((array) ($data['data'] ?? []))
        );
    }
}
