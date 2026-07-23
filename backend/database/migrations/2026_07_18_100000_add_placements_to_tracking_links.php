<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-platform tracking links: one link can be declared on several platforms
 * (x + linkedin + …). `placement` stays as the first entry for back-compat;
 * the actual per-platform click split comes from tracking_clicks.inferred_platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_links', function (Blueprint $table) {
            $table->json('placements')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tracking_links', function (Blueprint $table) {
            $table->dropColumn('placements');
        });
    }
};
