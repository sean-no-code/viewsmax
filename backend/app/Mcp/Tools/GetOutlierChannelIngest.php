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

#[Title("Check a channel import")]
#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class GetOutlierChannelIngest extends ViewsMaxTool
{
    protected function requiresWrite(): bool
    {
        return false;
    }

    public function shouldRegister(): bool
    {
        return config('services.outliers.channel_ingest_enabled') && parent::shouldRegister();
    }

    public function name(): string
    {
        return 'get_outlier_channel_ingest';
    }

    public function description(): string
    {
        return 'Poll a channel add started by add_outlier_channel. `status` is queued, '
            . 'processing, done (then `channel` is set and `videos_added` says how many videos '
            . 'landed — use channel.id in list_outliers `channels`), or failed (then `error` '
            . 'explains why). Pulls take ~10-60 seconds; poll every 5-10 seconds.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('ingest_id')->description('The ingest_id returned by add_outlier_channel.')->required();
    }

    public function handle(array $arguments): ToolResult
    {
        $validated = Validator::validate($arguments, [
            'ingest_id' => 'required|integer|min:1',
        ]);

        return $this->callController(
            fn (Request $request) => app(OutlierChannelIngestController::class)->show($request, (int) $validated['ingest_id']),
            [],
            fn (array $data) => $data['data'] ?? $data
        );
    }
}
