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
        Schema::create('copy_thumbnails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            // Source and result images
            $table->string('source_image_path'); // Original uploaded image
            $table->string('result_image_path')->nullable(); // Generated result
            
            // Processing status
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('comfy_prompt_id')->nullable(); // ComfyUI prompt ID for polling
            $table->text('error_message')->nullable();
            $table->json('processing_log')->nullable(); // Detailed logs for debugging
            
            // Configuration used for this generation
            $table->integer('resolution_width')->default(512);
            $table->integer('resolution_height')->default(512);
            $table->boolean('dw_pose_enabled')->default(true);
            
            // Optional: AI model for LoRA
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            
            $table->timestamps();
            
            // Indexes for common queries
            $table->index(['user_id', 'status']);
            $table->index('comfy_prompt_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('copy_thumbnails');
    }
};
