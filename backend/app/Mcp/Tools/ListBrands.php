<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('List brands')]
#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ListBrands extends ViewsMaxTool
{
    protected function requiresWrite(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'list_brands';
    }

    public function description(): string
    {
        return 'List the user\'s brands — named groups of connected accounts '
            . '(e.g. a brand holding a TikTok, an X and a YouTube account). '
            . 'Pass a brand id to create_post as brand_id to post to every '
            . 'connected account in the brand at once. Accounts with a status '
            . 'other than "connected" are skipped when posting.';
    }

    public function handle(array $arguments): ToolResult
    {
        $brands = $this->user()->brands()
            ->with(['socialAccounts', 'connections'])
            ->orderBy('name')
            ->get();

        return ToolResult::json([
            'brands' => $brands->map(fn ($brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
                'accounts' => $brand->socialAccounts->map(fn ($a) => [
                    'platform' => $a->platform,
                    'account_name' => $a->name ?? $a->username,
                    'status' => $a->status,
                    'social_account_id' => $a->id,
                    'connection_id' => null,
                ])->concat($brand->connections->map(fn ($c) => [
                    'platform' => $c->provider,
                    'account_name' => $c->account_name,
                    'status' => 'connected',
                    'social_account_id' => null,
                    'connection_id' => $c->id,
                ]))->values()->all(),
            ])->all(),
        ]);
    }
}
