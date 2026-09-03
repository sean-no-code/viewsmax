<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Idempotent: the outlier DB may already be provisioned (see the
        // create_search_terms_tables migration for why).
        if (Schema::connection('outlier_db')->hasTable('search_results')) {
            return;
        }

        Schema::connection('outlier_db')->create('search_results', function (Blueprint $table) {
            $table->id();
            // Using outlier_db so FK to search_terms works natively
            $table->foreignId('term_id')->constrained('search_terms')->onDelete('cascade');
            
            // This is a string ID from the MAIN DB. No FK constraint possible across databases.
            $table->string('video_youtube_id');
            
            $table->timestamps();
            
            // Compound index for efficient lookup of a term's videos in order
            $table->index('term_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('outlier_db')->dropIfExists('search_results');
    }
};
