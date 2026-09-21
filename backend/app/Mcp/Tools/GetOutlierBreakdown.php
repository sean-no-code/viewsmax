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

#[Title('Get an outlier breakdown')]
#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class GetOutlierBreakdown extends ViewsMaxTool
{
    protected function requiresWrite(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'get_outlier_breakdown';
    }

    public function description(): string
    {
        return 'Read the AI breakdown of an outlier video (hook, structure, why it worked, '
            . 'how to replicate it). `status` is none (never generated — call '
            . 'generate_outlier_breakdown), pending/processing (poll again), completed '
            . '(`payload` holds the analysis) or failed (`error`).';
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
            fn (Request $request) => app(OutlierBreakdownController::class)->show($request, $validated['platform'], $validated['video_id']),
            [],
            fn (array $data) => $data['data'] ?? $data
        );
    }
}
