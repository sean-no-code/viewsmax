<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user saved Outlier filter presets. Lives on the outlier_db connection with the
 * rest of the outlier data; `user_id` references users on the main DB, so it is a plain
 * indexed column (no cross-connection FK). `filters` holds the full filter payload as
 * JSON — including the selected platform + channels — so a saved filter restores the
 * whole Outliers search state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('outlier_db')->create('outlier_saved_filters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->json('filters');
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::connection('outlier_db')->dropIfExists('outlier_saved_filters');
    }
};
