<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\PostController;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('List posts')]
#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ListPosts extends ViewsMaxTool
{
    protected function requiresWrite(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'list_posts';
    }

    public function description(): string
    {
        return 'List the user\'s posts, newest first, with per-platform publish status. '
            . 'Filter by status (draft, scheduled, posted) and/or a scheduled_at date '
            . 'window (from/to, ISO-8601). Returns one page at a time (' . self::PAGE_SIZE . ' by default); when '
            . 'has_more is true, ask for the next page.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('status')->description('draft, scheduled, or posted.')->optional()
            ->string('from')->description('Earliest scheduled_at (ISO-8601 date).')->optional()
            ->string('to')->description('Latest scheduled_at (ISO-8601 date).')->optional()
            ->integer('limit')->description('Posts per page (1-' . self::MAX_PAGE_SIZE . ', default ' . self::PAGE_SIZE . ').')->optional()
            ->integer('page')->description('Page number, starting at 1. Check has_more in the reply for further pages.')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        $validated = Validator::validate($arguments, [
            'status' => 'nullable|string|in:' . implode(',', [Post::STATUS_DRAFT, Post::STATUS_SCHEDULED, Post::STATUS_POSTED]),
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            ...self::pageRules(),
        ]);

        // PostController::index has no limit concept, so page after delegating.
        return $this->callController(
            fn (Request $request) => app(PostController::class)->index($request),
            array_intersect_key($validated, array_flip(['status', 'from', 'to'])),
            fn (array $data) => self::pageOf($data['data'], $validated, 'posts', fn ($post) => $this->serializePost($post))
        );
    }
}
