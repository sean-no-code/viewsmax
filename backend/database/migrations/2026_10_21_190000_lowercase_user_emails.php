<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Emails are now stored lowercase (User::normalizeEmail). Bring existing rows in
 * line so login and the unique check find them. A row whose lowercase form is
 * already taken by another account is a real duplicate signup: it is left as is
 * and logged for someone to merge by hand, because picking the survivor is not
 * a migration's call.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mixedCase = DB::table('users')->whereRaw('email <> lower(email)')->get(['id', 'email']);

        foreach ($mixedCase as $row) {
            $lower = mb_strtolower(trim($row->email));
            $taken = DB::table('users')->where('email', $lower)->where('id', '<>', $row->id)->exists();

            if ($taken) {
                // error level: warnings don't reach Sentry, and this needs a person.
                Log::error('Duplicate account: same email in two spellings, merge by hand', ['user_id' => $row->id, 'email' => $row->email]);

                continue;
            }

            DB::table('users')->where('id', $row->id)->update(['email' => $lower]);
        }
    }

    public function down(): void
    {
        // The original casing is not kept; lowercase addresses stay valid.
    }
};
