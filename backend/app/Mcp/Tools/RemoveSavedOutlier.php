<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\SavedOutlierController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class RemoveSavedOutlier extends ViewsMaxTool
{
    public function name(): string
    {
        return 'remove_saved_outlier';
    }

    public function description(): string
    {
        return 'Remove a saved video from the user\'s outlier library by its saved id '
            . '(from list_saved_outliers / save_outlier). The video itself stays in the '
            . 'outlier database.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->integer('id')->description('The saved-outlier id.')->required();
    }

    public function handle(array $arguments): ToolResult
    {
        $validated = Validator::validate($arguments, ['id' => 'required|integer']);

        return $this->callController(
            fn (Request $request) => app(SavedOutlierController::class)->destroy($request, $validated['id'])
        );
    }
}
