<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\ToolResult;

class ListConnectedAccounts extends ViewsMaxTool
{
    protected function requiresWrite(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'list_connected_accounts';
    }

    public function description(): string
    {
        return 'List every social account the user has connected (YouTube, TikTok, X, '
            . 'LinkedIn, Threads, Instagram, Beehiiv) — a platform can appear more '
            . 'than once when several accounts are connected on it. Each entry '
            . 'carries social_account_id or connection_id (matching list_brands) '
            . 'plus its store. Posts can only publish to connected platforms; use '
            . 'get_connect_url for anything missing. Beehiiv is connected via an '
            . 'API key on the Connections page, not get_connect_url, and is used '
            . 'for newsletter reach, not posting.';
    }

    public function handle(array $arguments): ToolResult
    {
        $user = $this->user();

        // Accounts live in two stores (newer SocialAccount + legacy Connection);
        // merge them the same way the frontend does. Every account is returned —
        // multi-account platforms would be hidden by a per-platform dedup.
        $accounts = $user->socialAccounts()->get()->map(fn ($a) => [
            'platform' => $a->platform,
            'account_name' => $a->name ?? $a->username,
            'username' => $a->username,
            'status' => $a->status,
            'social_account_id' => $a->id,
            'connection_id' => null,
            'store' => 'social',
        ]);

        $legacy = $user->connections()->get()->map(fn ($c) => [
            'platform' => $c->provider,
            'account_name' => $c->account_name,
            'username' => null,
            'status' => 'connected',
            'social_account_id' => null,
            'connection_id' => $c->id,
            'store' => 'legacy',
        ]);

        // Beehiiv is a separate connection type (API key, not OAuth) that lives
        // in its own model — not part of either store above.
        $beehiiv = collect();
        if ($conn = $user->beehiivConnection) {
            $beehiiv->push([
                'platform' => 'beehiiv',
                'account_name' => $conn->publication_name,
                'username' => null,
                'status' => $conn->status,
                'social_account_id' => null,
                'connection_id' => null,
                'store' => 'beehiiv',
            ]);
        }

        return ToolResult::json([
            'accounts' => $accounts->concat($legacy)->concat($beehiiv)->values()->all(),
        ]);
    }
}
