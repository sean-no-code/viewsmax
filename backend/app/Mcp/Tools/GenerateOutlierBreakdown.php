<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\OutlierBreakdownController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Generate an outlier breakdown')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(true)]
class GenerateOutlierBreakdown extends ViewsMaxTool
{
    public function name(): string
    {
        return 'generate_outlier_breakdown';
    }

    public function description(): string
    {
        return 'Queue an AI breakdown of an outlier video (transcript + analysis of the hook, '
            . 'structure and why it over-performed). Generation runs in the background and '
            . 'takes up to a couple of minutes — poll get_outlier_breakdown until status is '
            . 'completed. Re-running for a video that already has a breakdown returns the '
            . 'existing one instead of regenerating.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('platform')->description('youtube, tiktok, or instagram.')->required()
            ->string('video_id')->description('The platform\'s video id (must already exist — see fetch_outlier).')->required();
    }

    public function handle(array $arguments): ToolResult
    {
        if ($limited = $this->hourlyLimit('generate_breakdown')) {
            return $limited;
        }

        $validated = Validator::validate($arguments, [
            'platform' => 'required|string|in:youtube,tiktok,instagram',
            'video_id' => 'required|string|max:255',
        ]);

        return $this->callController(
            fn (Request $request) => app(OutlierBreakdownController::class)->store($request, $validated['platform'], $validated['video_id']),
            [],
            fn (array $data) => $data['data'] ?? $data
        );
    }
}
