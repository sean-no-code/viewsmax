<?php

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class GetPost extends ViewsMaxTool
{
    protected function requiresWrite(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'get_post';
    }

    public function description(): string
    {
        return 'Fetch one post by id, including each platform target\'s publish '
            . 'status (pending, publishing, published, failed) and error message.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->integer('id')->description('The post id.');
    }

    public function handle(array $arguments): ToolResult
    {
        $validated = Validator::validate($arguments, ['id' => 'required|integer']);

        $post = $this->user()->posts()->with('targets')->find($validated['id']);

        if (! $post) {
            return ToolResult::error("Post {$validated['id']} not found.");
        }

        return ToolResult::json($this->serializePost($post));
    }
}
