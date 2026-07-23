<?php

use App\Models\Connection;
use App\Models\SocialAccount;
use Illuminate\Database\Migrations\Migration;

/**
 * Multi-account groundwork for TikTok + YouTube. These two platforms still live
 * in the single-account `connections` store; every multi-account platform uses
 * `social_accounts`. Copy each existing TikTok/YouTube connection into a
 * SocialAccount row so nobody has to reconnect once publishing switches to the
 * SocialAccount store. Idempotent (keyed on user+platform+account id), and it
 * only ADDS rows — the `connections` rows stay put for anything still reading
 * them until that path is fully retired.
 *
 * Uses the Eloquent models so the encrypted-token casts round-trip correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Connection::query()
            ->whereIn('provider', ['tiktok', 'youtube'])
            ->whereNotNull('account_id')
            ->where('account_id', '!=', '')
            ->cursor()
            ->each(function (Connection $c) {
                SocialAccount::updateOrCreate(
                    [
                        'user_id' => $c->user_id,
                        'platform' => $c->provider,
                        'platform_account_id' => $c->account_id,
                    ],
                    [
                        'name' => $c->account_name,
                        'avatar_url' => $c->avatar_url,
                        'access_token' => $c->access_token,
                        'refresh_token' => $c->refresh_token,
                        'token_expires_at' => $c->token_expires_at,
                        'status' => SocialAccount::STATUS_CONNECTED,
                        'scopes' => config("social.platforms.{$c->provider}.scopes", []),
                        'last_synced_at' => now(),
                    ]
                );
            });
    }

    public function down(): void
    {
        // Only remove rows that clearly originated from a legacy connection — a
        // matching (user, platform, account id) pair still in `connections`.
        Connection::query()
            ->whereIn('provider', ['tiktok', 'youtube'])
            ->whereNotNull('account_id')
            ->cursor()
            ->each(function (Connection $c) {
                SocialAccount::query()
                    ->where('user_id', $c->user_id)
                    ->where('platform', $c->provider)
                    ->where('platform_account_id', $c->account_id)
                    ->delete();
            });
    }
};
