<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Disconnecting an account now soft-deletes its row instead of removing it, so we
// keep a record of which platform accounts were connected (and by whom). Tokens
// are cleared on disconnect (App\Models\Concerns\SoftDeletesConnection).
return new class extends Migration
{
    private const TABLES = ['social_accounts', 'connections', 'channels'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
