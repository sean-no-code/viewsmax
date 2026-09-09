<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\AutomationController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class UpdateAutomation extends ViewsMaxTool
{
    public function name(): string
    {
        return 'update_automation';
    }

    public function description(): string
    {
        return 'Update an automation\'s name, trigger conditions, reply or DM. Same fields as create_automation; only the provided fields change.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('id')->description('Automation id.')
            ->string('dm_text')->description('The DM (<= 1000 chars; <= 80 when a button is set).')->optional()
            ->string('name')->description('Display name.')->optional()
            ->string('post_match')->description('Comment triggers: specific or any.')->optional()
            ->raw('posts', ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'thumbnail_url' => ['type' => 'string'], 'permalink' => ['type' => 'string'], 'caption' => ['type' => 'string']], 'required' => ['id']], 'description' => 'Specific posts/reels (media ids from the Instagram media picker).'])
            ->boolean('include_replies')->description('Also fire on replies inside comment threads.')->optional()
            ->string('keyword_mode')->description('any, contains or exact.')->optional()
            ->raw('keywords', ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Keywords (max 30).'])
            ->integer('cooldown_hours')->description('Per-sender re-fire guard in hours (0 = off, default 24).')->optional()
            ->boolean('reply_enabled')->description('Comment triggers: post a public reply under the comment.')->optional()
            ->raw('reply_texts', ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Public reply variants (one picked at random, max 5).'])
            ->string('dm_subtitle')->description('Card subtitle (<= 80 chars) when a button is set.')->optional()
            ->string('dm_image_url')->description('https image for the card.')->optional()
            ->string('dm_button_label')->description('Button label (<= 20 chars).')->optional()
            ->string('dm_button_url')->description('Button destination; sent as a tracked link.')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        $id = (int) ($arguments['id'] ?? 0);
        unset($arguments['id']);

        return $this->callController(
            fn ($request) => app(AutomationController::class)->update($request, $id),
            $arguments
        );
    }
}
