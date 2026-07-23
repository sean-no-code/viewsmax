<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\PostController;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class ListPosts extends ViewsMaxTool
{
    private const DEFAULT_LIMIT = 25;

    private const MAX_LIMIT = 100;

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
            . 'window (from/to, ISO-8601). Returns at most `limit` posts (default 25).';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('status')->description('draft, scheduled, or posted.')->optional()
            ->string('from')->description('Earliest scheduled_at (ISO-8601 date).')->optional()
            ->string('to')->description('Latest scheduled_at (ISO-8601 date).')->optional()
            ->integer('limit')->description('Max posts to return (1-100, default 25).')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        $validated = Validator::validate($arguments, [
            'status' => 'nullable|string|in:' . implode(',', [Post::STATUS_DRAFT, Post::STATUS_SCHEDULED, Post::STATUS_POSTED]),
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
        ]);

        $limit = $validated['limit'] ?? self::DEFAULT_LIMIT;

        // PostController::index has no limit concept, so cap after delegating.
        return $this->callController(
            fn (Request $request) => app(PostController::class)->index($request),
            array_intersect_key($validated, array_flip(['status', 'from', 'to'])),
            fn (array $data) => [
                'posts' => collect($data['data'])
                    ->take($limit)
                    ->map(fn ($post) => $this->serializePost($post))
                    ->all(),
            ]
        );
    }
}
