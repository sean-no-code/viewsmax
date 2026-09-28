<?php

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Delete a post')]
#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
class DeletePost extends ViewsMaxTool
{
    public function name(): string
    {
        return 'delete_post';
    }

    public function description(): string
    {
        return 'Delete a post and its platform targets. Does not remove content '
            . 'already published to the platforms.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->integer('id')->description('The post id.')->required();
    }

    public function handle(array $arguments): ToolResult
    {
        $validated = Validator::validate($arguments, ['id' => 'required|integer']);

        $post = $this->user()->posts()->find($validated['id']);

        if (! $post) {
            return ToolResult::error("Post {$validated['id']} not found.");
        }

        $post->delete();

        return ToolResult::json(['deleted' => true, 'id' => (int) $validated['id']]);
    }
}
