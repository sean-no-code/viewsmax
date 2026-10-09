<?php

namespace App\Support;

use App\Exceptions\AccountAlreadyConnectedException;
use App\Models\Channel;
use App\Models\Connection;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A platform account belongs to the first ViewsMax user that connected it.
 * Looks across every store an account can live in (social_accounts,
 * connections, channels), including disconnected (soft-deleted) rows, so
 * signing up again with the same channel can't be used to collect new free
 * credits.
 */
class AccountOwnership
{
    /**
     * @throws AccountAlreadyConnectedException when another user connected it first
     */
    public static function ensureAvailable(?int $userId, string $platform, ?string $accountId): void
    {
        if (! $userId || $accountId === null || $accountId === '') {
            return;
        }

        $owners = DB::table('social_accounts')
            ->select('user_id', 'created_at')
            ->where('platform', $platform)->where('platform_account_id', $accountId)->where('user_id', '!=', $userId)
            ->unionAll(DB::table('connections')
                ->select('user_id', 'created_at')
                ->where('provider', $platform)->where('account_id', $accountId)->where('user_id', '!=', $userId));

        if ($platform === 'youtube') {
            $owners->unionAll(DB::table('channels')
                ->select('user_id', 'created_at')
                ->where('youtube_channel_id', $accountId)->whereNotNull('user_id')->where('user_id', '!=', $userId));
        }

        $first = DB::query()->fromSub($owners, 'owners')->orderBy('created_at')->first();
        $owner = $first ? User::withTrashed()->find($first->user_id) : null;

        if ($owner) {
            throw AccountAlreadyConnectedException::forOwner($owner, $platform);
        }
    }

    /**
     * One platform account can be stored in up to three places for a user (a
     * social account, a connection, and for YouTube a channel), and different
     * screens read different ones. Disconnecting it in one place disconnects
     * the rest, so it can't linger in e.g. the post composer. Each delete runs
     * its model's own disconnect handling; rows already disconnected are
     * skipped, which also ends the chain.
     */
    public static function disconnectEverywhere(?int $userId, string $platform, ?string $accountId): void
    {
        if (! $userId || $accountId === null || $accountId === '') {
            return;
        }

        SocialAccount::where('user_id', $userId)->where('platform', $platform)->where('platform_account_id', $accountId)->get()->each->delete();
        Connection::where('user_id', $userId)->where('provider', $platform)->where('account_id', $accountId)->get()->each->delete();

        if ($platform === 'youtube') {
            Channel::where('user_id', $userId)->where('youtube_channel_id', $accountId)->get()->each->delete();
        }
    }
}
