<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE script_researches DROP CONSTRAINT IF EXISTS script_researches_status_check");
        DB::statement("ALTER TABLE script_researches ADD CONSTRAINT script_researches_status_check CHECK (status IN ('pending', 'processing', 'completed', 'failed'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE script_researches DROP CONSTRAINT IF EXISTS script_researches_status_check");
        DB::statement("ALTER TABLE script_researches ADD CONSTRAINT script_researches_status_check CHECK (status IN ('pending', 'completed', 'failed'))");
    }
};
