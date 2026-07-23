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
        Schema::create('title_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained('videos')->onDelete('cascade');
            
            // AI-only checks scores (1-10)
            $table->tinyInteger('fre')->unsigned()->default(0); // Flesch Reading Ease score
            $table->tinyInteger('clarity')->unsigned()->default(0);
            $table->tinyInteger('stakes')->unsigned()->default(0);
            $table->tinyInteger('curiosity_gap')->unsigned()->default(0);
            $table->tinyInteger('emotional_trigger')->unsigned()->default(0);
            $table->tinyInteger('concreteness')->unsigned()->default(0);
            $table->tinyInteger('human_element')->unsigned()->default(0);
            $table->tinyInteger('scale')->unsigned()->default(0);
            $table->tinyInteger('visualizability')->unsigned()->default(0);
            $table->tinyInteger('specificity')->unsigned()->default(0);
            $table->tinyInteger('no_cleverness')->unsigned()->default(0);
            
            $table->timestamps();
            
            // Unique constraint to ensure one score per video
            $table->unique('video_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('title_scores');
    }
};
