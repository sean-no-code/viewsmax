<?php

namespace App\Models\Concerns;

use App\Support\AccountOwnership;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A connected account (social account, OAuth connection, YouTube channel) is
 * soft-deleted on disconnect: it disappears from the app but the row stays, so
 * we keep a record of which platform account was connected and by whom.
 *
 * On disconnect the credentials listed in the model's $clearedOnDisconnect are
 * wiped, so we stop holding access to an account the user removed. Reconnecting
 * the same account goes through reconnect(), which updates and restores the
 * hidden row instead of inserting a duplicate (the unique keys still cover it).
 *
 * Connecting is refused when another user connected the same platform account
 * first, even if they disconnected it since (AccountOwnership).
 *
 * The model declares:
 *   protected array $clearedOnDisconnect = ['column' => value, ...];
 *   protected static function accountIdentity(array $row): array  // [user_id, platform, account id]
 */
trait SoftDeletesConnection
{
    use SoftDeletes;

    public static function bootSoftDeletesConnection(): void
    {
        static::softDeleted(function (self $model) {
            $model->forceFill($model->clearedOnDisconnect)->saveQuietly();
        });
    }

    /**
     * Connect (or reconnect) an account: like updateOrCreate, but a previously
     * disconnected row with the same keys is updated and restored.
     */
    public static function reconnect(array $attributes, array $values = []): static
    {
        [$userId, $platform, $accountId] = static::accountIdentity($attributes + $values);
        AccountOwnership::ensureAvailable($userId, $platform, $accountId);

        $model = static::withTrashed()->updateOrCreate($attributes, $values);

        if ($model->trashed()) {
            $model->restore();
        }

        return $model;
    }

    /**
     * The owner, platform and platform account id of a row being connected.
     *
     * @return array{0: ?int, 1: string, 2: ?string}
     */
    abstract protected static function accountIdentity(array $row): array;
}
