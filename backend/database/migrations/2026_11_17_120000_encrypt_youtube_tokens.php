<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Encrypt the YouTube OAuth tokens that were stored as plaintext:
 * users.youtube_access_token / youtube_refresh_token and
 * channels.youtube_access_token / youtube_refresh_token. The User and Channel
 * models now cast these columns as `encrypted`, and that cast throws on a
 * plaintext value, so this migration must run in the same deploy.
 *
 * Works through the query builder and Crypt (encryptString, the format the
 * `encrypted` cast reads and writes), never through the models, so it does not
 * depend on their future shape. Rows are processed in id order in chunks.
 * Null values are skipped, and a value that already decrypts with the current
 * APP_KEY is left alone, so running it twice changes nothing. An empty string
 * becomes NULL: it is not a usable token, and the cast cannot decrypt it.
 *
 * The four columns are TEXT (2025_09_12_052637 and 2025_11_12_061014), which
 * holds the longer encrypted value on PostgreSQL and SQLite, so no column
 * change is needed.
 */
return new class extends Migration
{
    private const COLUMNS = ['youtube_access_token', 'youtube_refresh_token'];

    private const TABLES = ['users', 'channels'];

    private const CHUNK = 200;

    public function up(): void
    {
        $this->rewrite(function (string $value) {
            if ($value === '') {
                return null;
            }

            return $this->isEncrypted($value) ? false : Crypt::encryptString($value);
        });
    }

    /** Back to plaintext. Values that do not decrypt (already plaintext) are left alone. */
    public function down(): void
    {
        $this->rewrite(function (string $value) {
            try {
                return $value === '' ? false : Crypt::decryptString($value);
            } catch (DecryptException) {
                return false;
            }
        });
    }

    /**
     * Apply $transform to every non-null token value. $transform returns the
     * value to store (a string, or null to clear it), or false to leave the
     * stored value as it is.
     *
     * @param  callable(string): (string|null|false)  $transform
     */
    private function rewrite(callable $transform): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)
                ->select(array_merge(['id'], self::COLUMNS))
                ->where(function ($q) {
                    foreach (self::COLUMNS as $column) {
                        $q->orWhereNotNull($column);
                    }
                })
                ->chunkById(self::CHUNK, function ($rows) use ($table, $transform) {
                    foreach ($rows as $row) {
                        $changes = [];
                        foreach (self::COLUMNS as $column) {
                            $value = $row->{$column};
                            if ($value === null) {
                                continue;
                            }
                            $new = $transform((string) $value);
                            if ($new !== false) {
                                $changes[$column] = $new;
                            }
                        }

                        if ($changes !== []) {
                            DB::table($table)->where('id', $row->id)->update($changes);
                        }
                    }
                });
        }
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
