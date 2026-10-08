<?php

namespace App\Mcp\Tools;

use App\Jobs\IngestOutlierByUrlJob;
use App\Mcp\NextSteps;
use App\Models\OutlierBreakdown;
use App\Models\OutlierVideo;
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
            . 'or fetch_outlier), including its channel, views, outlier score and engagement. '
            . '`status` is "ready" (the video fields follow) or "ingesting" (fetch_outlier queued '
            . 'the download and it has not landed yet — poll again in about 15 seconds; Instagram '
            . 'can take minutes). A video that was never fetched, or whose download failed, is an error.';
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
        $platform = $validated['platform'];
        $videoId = $validated['video_id'];

        $video = OutlierVideo::with('channel')
            ->where('platform', $platform)
            ->where('youtube_video_id', $videoId)
            ->first();

        if ($video) {
            return ToolResult::json(['status' => 'ready'] + $this->serializeOutlier($video->toArray()) + ['next_steps' => NextSteps::READY_FOR_BREAKDOWN]);
        }

        // No row yet. fetch_outlier answers "queued" and tells the client to
        // poll here, so a poll that lands before the download finishes is a
        // state, not a failure — reporting it as an error made every poll of
        // a slow Instagram ingest count as a failed tool call.
        $failed = OutlierBreakdown::where('platform', $platform)
            ->where('video_id', $videoId)
            ->where('status', OutlierBreakdown::STATUS_FAILED)
            ->first();
        if ($failed?->error) {
            return ToolResult::error($failed->error . ' Call fetch_outlier with the URL again to retry.');
        }

        if (IngestOutlierByUrlJob::isInFlight($platform, $videoId)) {
            return ToolResult::json([
                'status' => 'ingesting',
                'platform' => $platform,
                'video_id' => $videoId,
                'message' => 'Still being fetched. Check again in about 15 seconds (Instagram can take a few minutes).',
                'next_steps' => NextSteps::INGESTING,
            ]);
        }

        return ToolResult::error(
            "Video {$videoId} not found on {$platform}. Call fetch_outlier with its URL to pull it in, "
            . 'or use the platform and video_id from a list_outliers result.'
        );
    }
}
