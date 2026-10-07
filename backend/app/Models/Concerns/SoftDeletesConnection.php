<?php

namespace App\Models\Concerns;

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
 * The model declares: protected array $clearedOnDisconnect = ['column' => value, ...];
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
        $model = static::withTrashed()->updateOrCreate($attributes, $values);

        if ($model->trashed()) {
            $model->restore();
        }

        return $model;
    }
}
