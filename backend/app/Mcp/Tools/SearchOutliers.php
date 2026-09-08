<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\OutlierController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class SearchOutliers extends ViewsMaxTool
{
    public function name(): string
    {
        return 'search_outliers';
    }

    public function description(): string
    {
        return 'Start a background scrape for outlier videos matching a keyword/topic. '
            . 'Returns immediately with status "queued"; results land in the shared '
            . 'outlier database over the next minute or two — poll list_outliers with the '
            . 'same `query` until its status is "done". Use exact_match to require the '
            . 'whole phrase.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('term')->description('Keyword or topic to search for.')->required()
            ->boolean('exact_match')->description('Match the whole phrase only (default false).')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        if ($limited = $this->hourlyLimit('search_outliers')) {
            return $limited;
        }

        $validated = Validator::validate($arguments, [
            'term' => 'required|string|min:2|max:200',
            'exact_match' => 'nullable|boolean',
        ]);

        return $this->callController(
            fn (Request $request) => app(OutlierController::class)->search($request),
            $validated
        );
    }
}
