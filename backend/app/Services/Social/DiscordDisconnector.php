<?php

namespace App\Services\Social;

use App\Models\User;
use App\Services\Social\Providers\DiscordProvider;

/**
 * Disconnecting a Discord channel deletes its webhook in Discord as well as
 * the account row: the webhook token is posting rights in that channel, and a
 * webhook left behind would keep them alive after the user said "disconnect".
 * Deleting it is best effort — the row goes either way; a refusal is logged
 * at error level by DiscordProvider::deleteWebhook. Every disconnect path
 * (Connections page, the MCP tool, user:erase) comes through here, the way
 * YouTube's comes through YouTubeDisconnector.
 */
final class DiscordDisconnector
{
    /**
     * @param  int|null  $socialAccountId  one account, or null for every Discord account the user has
     * @return array{webhooks_deleted: int, social_accounts: int}
     */
    public static function disconnect(User $user, ?int $socialAccountId = null): array
    {
        $accounts = $user->socialAccounts()->where('platform', 'discord')
            ->when($socialAccountId !== null, fn ($q) => $q->whereKey($socialAccountId))
            ->get();

        $provider = app(DiscordProvider::class);

        $deleted = 0;
        foreach ($accounts as $account) {
            $webhookId = (string) $account->meta('webhook_id', '');
            $token = (string) $account->access_token;
            if ($webhookId !== '' && $token !== '' && $provider->deleteWebhook($webhookId, $token)) {
                $deleted++;
            }
            $account->delete();
        }

        return ['webhooks_deleted' => $deleted, 'social_accounts' => $accounts->count()];
    }
}
