<?php

namespace App\Support;

use App\Exceptions\AccountAlreadyConnectedException;
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
}
