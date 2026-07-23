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
        Schema::create('thumbnail_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained('videos')->onDelete('cascade');

            // AI-only checks scores (1-10)
            $table->tinyInteger('face_detection')->unsigned()->default(0);
            $table->tinyInteger('expression_analysis')->unsigned()->default(0);
            $table->tinyInteger('object_counting')->unsigned()->default(0);
            $table->tinyInteger('contrast')->unsigned()->default(0);
            $table->tinyInteger('brightness')->unsigned()->default(0);
            $table->tinyInteger('saturation')->unsigned()->default(0);
            $table->tinyInteger('clutter_score')->unsigned()->default(0);
            $table->tinyInteger('readability_check')->unsigned()->default(0);
            $table->tinyInteger('curiosity_gap_estimation')->unsigned()->default(0);
            $table->tinyInteger('story_clarity_estimation')->unsigned()->default(0);
            $table->tinyInteger('title_thumbnail_alignment')->unsigned()->default(0);
            $table->tinyInteger('safety_classification')->unsigned()->default(0);

            // Pure code/computer vision checks (1-10)
            $table->tinyInteger('dimensions')->unsigned()->default(0);
            $table->tinyInteger('file_format')->unsigned()->default(0);
            $table->tinyInteger('file_size')->unsigned()->default(0);
            $table->tinyInteger('histogram_contrast')->unsigned()->default(0);
            $table->tinyInteger('sharpness')->unsigned()->default(0);
            $table->tinyInteger('noise')->unsigned()->default(0);
            $table->tinyInteger('rule_of_thirds')->unsigned()->default(0);
            $table->tinyInteger('text_detection_count')->unsigned()->default(0);

            // Status tracking
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('thumbnail_file_path')->nullable();

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
        Schema::dropIfExists('thumbnail_scores');
    }
};
