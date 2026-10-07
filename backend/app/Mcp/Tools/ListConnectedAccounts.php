<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('List connected accounts')]
#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
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
        return 'List every social account the user has connected, plus a Beehiiv '
            . 'newsletter connection if there is one — a platform can appear more '
            . 'than once when several accounts are connected on it. Each entry '
            . 'carries social_account_id or connection_id (matching list_brands) '
            . 'plus its store; a connection_id-only (legacy) entry is shown only '
            . 'for platforms with no social_account_id entry. username is the '
            . "platform's public handle where it has one, never an email. Posts "
            . 'can only publish to connected platforms; use '
            . 'get_connect_url for anything missing. Beehiiv is connected via an '
            . 'API key on the Connections page, not get_connect_url, and is used '
            . 'for newsletter reach, not posting.';
    }

    public function handle(array $arguments): ToolResult
    {
        $user = $this->user();

        // Accounts live in two stores: SocialAccount, which publishing reads,
        // and the legacy Connection rows the YouTube/TikTok flows still write
        // and then mirror into SocialAccount. A mirrored connection is the same
        // account, not a second one: its connection_id is carried on the social
        // row (so list_brands still cross-references) instead of a duplicate
        // row. Several social accounts on one platform are all returned.
        $social = $user->socialAccounts()->get();
        $connections = $user->connections()->get();

        $accounts = $social->map(function ($a) use ($connections) {
            $mirror = $connections->first(fn ($c) => $c->provider === $a->platform
                && (string) $c->account_id === (string) $a->platform_account_id);
            $handle = self::publicHandle($a->username);

            return [
                'platform' => $a->platform,
                'account_name' => $a->name ?? $handle,
                'username' => $handle,
                'status' => $a->status,
                'social_account_id' => $a->id,
                'connection_id' => $mirror?->id,
                'store' => 'social',
            ];
        });

        // Only connections that were never mirrored (pre-mirror connects) are
        // listed on their own.
        $mirroredPlatforms = $social->pluck('platform')->unique();
        $legacy = $connections->reject(fn ($c) => $mirroredPlatforms->contains($c->provider))->map(fn ($c) => [
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

        // Name what's supported, so a missing platform isn't read as "connect
        // it first" when it can't be connected at all.
        $all = $accounts->concat($legacy)->concat($beehiiv)->values()->all();

        $result = [
            'accounts' => $all,
            'supported_platforms' => CreatePost::availablePlatforms(),
            'note' => "Only the supported platforms can be connected and posted to. Other platforms can't "
                . 'be connected on ViewsMax yet.',
        ];

        // Nothing connected yet is the normal first state, not a failure: hand
        // the agent the Connections page so it can send the user there.
        if ($all === []) {
            $result['connect_page_url'] = GetConnectUrl::connectionsPageUrl();
            $result['next_step'] = 'No accounts are connected yet, so posting is not possible. Give the '
                . 'user the connect_page_url: they open it in a browser, sign in, and click Connect next '
                . 'to each platform they want. Approval happens on that page, not in this conversation; '
                . 'afterwards list_connected_accounts will show the new accounts.';
        }

        return ToolResult::json($result);
    }
}
