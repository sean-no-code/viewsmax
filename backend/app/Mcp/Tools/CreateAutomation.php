<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\AutomationController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class CreateAutomation extends ViewsMaxTool
{
    public function name(): string
    {
        return 'create_automation';
    }

    public function description(): string
    {
        return 'Create an Instagram automation (created stopped — call start_automation to go live). '
            . 'Requires social_account_id (a connected Instagram account with the comments + messages '
            . 'permissions), trigger_type (comment, story_reply or dm) and dm_text. Comment triggers need '
            . 'post_match ("specific" or "any") and, for specific, posts [{id}] from the Instagram media '
            . 'picker. keyword_mode is "any", "contains" or "exact" with keywords. Optional public reply '
            . '(reply_enabled + reply_texts) for comment triggers, and a tracked button (dm_button_label '
            . '<= 20 chars + dm_button_url; then dm_text <= 80 chars). Subject to the plan\'s automation limit.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('social_account_id')->description('Connected Instagram account id (see list_connected_accounts).')
            ->string('trigger_type')->description('comment, story_reply or dm.')
            ->string('dm_text')->description('The DM (<= 1000 chars; <= 80 when a button is set).')
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
        return $this->callController(
            fn ($request) => app(AutomationController::class)->store($request),
            $arguments
        );
    }
}
