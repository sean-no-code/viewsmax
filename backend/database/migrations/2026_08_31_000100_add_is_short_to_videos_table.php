<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Shorts vs long-form is a FORMAT, not a duration: YouTube serves Shorts at
// /shorts/{id} (200) and redirects everything else to /watch. Store the answer
// per row; null = not yet classified (query falls back to the old duration rule).
// Outlier-domain table → outlier_db connection.
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('outlier_db')->table('videos', function (Blueprint $table) {
            $table->boolean('is_short')->nullable();
            // When we last tried — stamped even when inconclusive so backfills don't loop on 404s.
            $table->timestamp('format_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('outlier_db')->table('videos', function (Blueprint $table) {
            $table->dropColumn(['is_short', 'format_checked_at']);
        });
    }
};
