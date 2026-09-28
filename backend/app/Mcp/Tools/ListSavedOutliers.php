<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\SavedOutlierController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('List saved outliers')]
#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ListSavedOutliers extends ViewsMaxTool
{
    protected function requiresWrite(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'list_saved_outliers';
    }

    public function description(): string
    {
        return 'The user\'s saved-outliers library (videos they bookmarked with save_outlier), '
            . 'newest first, with tags and the video snapshot taken when saved. Filter by a '
            . 'title/channel query, tag names, platforms, or creator name. Returns one page at '
            . 'a time (' . self::PAGE_SIZE . ' by default); when has_more is true, ask for the next page.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('q')->description('Matches saved title or channel name.')->optional()
            ->raw('tags', ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only items carrying any of these tag names.'])->optional()
            ->raw('platforms', ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'youtube, tiktok, instagram.'])->optional()
            ->string('creator')->description('Channel/creator name filter.')->optional()
            ->integer('limit')->description('Saved items per page (1-' . self::MAX_PAGE_SIZE . ', default ' . self::PAGE_SIZE . ').')->optional()
            ->integer('page')->description('Page number, starting at 1. Check has_more in the reply for further pages.')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        $validated = Validator::validate($arguments, [
            'q' => 'nullable|string|max:200',
            'tags' => 'nullable|array|max:50',
            'tags.*' => 'string|max:50',
            'platforms' => 'nullable|array',
            'platforms.*' => 'string|in:youtube,tiktok,instagram',
            'creator' => 'nullable|string|max:200',
            ...self::pageRules(),
        ]);

        return $this->callController(
            fn (Request $request) => app(SavedOutlierController::class)->index($request),
            array_filter(array_diff_key($validated, self::pageRules()), fn ($v) => $v !== null),
            fn (array $data) => self::pageOf($data['data'] ?? [], $validated, 'saved', fn ($row) => $this->serializeSaved((array) $row))
        );
    }

    public static function serializeSaved(array $row): array
    {
        return [
            'id' => $row['id'] ?? null,
            'platform' => $row['platform'] ?? null,
            'video_id' => $row['video_id'] ?? null,
            'tags' => array_values(array_map(fn ($t) => is_array($t) ? ($t['name'] ?? null) : $t, $row['tags'] ?? [])),
            'snapshot' => $row['snapshot'] ?? null,
            'saved_at' => $row['created_at'] ?? null,
        ];
    }
}
