<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Idempotent: the outlier DB may already carry this column (see the
        // create_search_terms_tables migration for why).
        if (Schema::connection('outlier_db')->hasColumn('terms_data_fetch', 'status')) {
            return;
        }

        Schema::connection('outlier_db')->table('terms_data_fetch', function (Blueprint $table) {
            $table->string('status')->default('queued')->after('term_id')->comment('queued, in_progress, done, failed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('outlier_db')->table('terms_data_fetch', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
