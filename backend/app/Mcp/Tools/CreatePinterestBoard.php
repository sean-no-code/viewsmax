<?php

namespace App\Mcp\Tools;

use App\Services\Social\SocialProviderManager;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Create a Pinterest board')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(true)]
class CreatePinterestBoard extends ViewsMaxTool
{
    public function name(): string
    {
        return 'create_pinterest_board';
    }

    public function description(): string
    {
        return 'Create a public board on one of the user\'s connected Pinterest accounts '
            . '(social_account_id from list_connected_accounts). Every Pin goes on a board, '
            . 'so use this when the user asks for a new board or has none. Only create a '
            . 'board the user named; never invent one. Returns the board to pass as '
            . 'options.pinterest.board_id.';
    }

    /**
     * Hidden until Pinterest has app keys, like Pinterest everywhere else.
     */
    public function isAvailable(): bool
    {
        return app(SocialProviderManager::class)->isConfigured('pinterest');
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('social_account_id')->description('The Pinterest account (from list_connected_accounts).')->required()
            ->string('name')->description('Board name, as the user gave it.')->required()
            ->string('description')->description('Optional board description.')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        $validated = Validator::validate($arguments, [
            'social_account_id' => 'required|integer',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
        ]);

        $account = $this->user()->socialAccounts()
            ->where('platform', 'pinterest')
            ->find($validated['social_account_id']);
        if (! $account) {
            return ToolResult::error('No connected Pinterest account with that social_account_id.');
        }

        $provider = app(SocialProviderManager::class)->for('pinterest');

        try {
            $board = $provider->createBoard(
                $provider->ensureFreshToken($account),
                trim($validated['name']),
                $validated['description'] ?? null,
            );
        } catch (\Throwable $e) {
            return ToolResult::error('Pinterest refused the board: '.$e->getMessage());
        }

        return ToolResult::json([
            'board' => $board,
            'next_steps' => 'Pass board.id as options.pinterest.board_id in create_post or update_post.',
        ]);
    }
}
