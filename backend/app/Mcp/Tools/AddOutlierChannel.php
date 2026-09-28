<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\OutlierChannelIngestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title("Add a creator's channel to outlier research")]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(true)]
class AddOutlierChannel extends ViewsMaxTool
{
    public function shouldRegister(): bool
    {
        return config('services.outliers.channel_ingest_enabled') && parent::shouldRegister();
    }

    public function name(): string
    {
        return 'add_outlier_channel';
    }

    public function description(): string
    {
        return 'Add a creator\'s channel to the outlier database from a profile URL or @handle '
            . '(YouTube, TikTok, Instagram) and pull in their 10 most recent videos, scored '
            . 'against that channel\'s own median. Also adds the channel to the user\'s '
            . 'competitor list. Not for video links — use fetch_outlier for those. Returns '
            . '`status: done` with the channel when it was pulled in the last 24 hours; otherwise '
            . '`queued: true` with an ingest_id — poll get_outlier_channel_ingest until `done`, '
            . 'then list_outliers with `channels: [channel.id]` (and `duration_type: shorts` for '
            . 'TikTok/Instagram) to see the videos. No AI breakdowns are generated.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('input')->description('Profile URL (youtube.com/@x, tiktok.com/@x, instagram.com/x) or a bare @handle.')->required()
            ->string('platform')->description('youtube, tiktok, or instagram. Required for a bare @handle; ignored when the URL says otherwise.')->optional()
            ->integer('max_videos')->description('How many recent videos to pull (5-50, default 10).')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        if ($limited = $this->hourlyLimit('add_outlier_channel')) {
            return $limited;
        }

        $validated = Validator::validate($arguments, [
            'input' => 'required|string|max:500',
            'platform' => 'nullable|string|in:youtube,tiktok,instagram',
            'max_videos' => 'nullable|integer|min:5|max:50',
        ]);

        return $this->callController(
            fn (Request $request) => app(OutlierChannelIngestController::class)->store($request),
            array_filter($validated, fn ($v) => $v !== null),
            fn (array $data) => [
                'queued' => (bool) ($data['queued'] ?? false),
                'status' => $data['status'] ?? null,
                'ingest_id' => $data['ingest_id'] ?? null,
                'platform' => $data['platform'] ?? null,
                'handle' => $data['handle'] ?? null,
                'channel' => $data['channel'] ?? null,
            ]
        );
    }
}
