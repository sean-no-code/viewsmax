<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user tags for the saved-outliers library + the pivot linking tags to saved outliers.
 * Lives on the outlier_db connection; `user_id` references users on the main DB, so it is
 * a plain indexed column (no cross-connection FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('outlier_db')->create('outlier_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->index('user_id');
        });

        Schema::connection('outlier_db')->create('saved_outlier_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saved_outlier_id')->constrained('saved_outliers')->cascadeOnDelete();
            $table->foreignId('outlier_tag_id')->constrained('outlier_tags')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['saved_outlier_id', 'outlier_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('outlier_db')->dropIfExists('saved_outlier_tag');
        Schema::connection('outlier_db')->dropIfExists('outlier_tags');
    }
};
