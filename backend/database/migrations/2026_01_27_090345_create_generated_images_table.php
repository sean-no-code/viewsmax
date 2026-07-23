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
        Schema::create('generated_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Generation method and quality
            $table->string('method'); // 'generate' or 'head_swap'
            $table->string('quality')->default('fast'); // 'fast', 'normal', 'high', 'very_high'
            $table->string('status')->default('pending'); // 'pending', 'processing', 'completed', 'failed'

            // User input
            $table->text('prompt')->nullable();

            // ComfyUI tracking
            $table->string('comfy_prompt_id')->nullable()->index();

            // Image paths
            $table->string('base_image_path'); // First/main image
            $table->string('reference_image_path')->nullable(); // For head_swap
            $table->json('additional_images')->nullable(); // Images 3-5
            $table->string('result_image_path')->nullable(); // Generated output
            $table->json('result_image_paths')->nullable();
            // Processing details
            $table->json('processing_log')->nullable();
            $table->text('error_message')->nullable();

            // Workflow parameters used
            $table->float('megapixel')->default(0.3);
            $table->integer('steps')->default(4);
            $table->boolean('refine_enabled')->default(false);
            $table->boolean('inpainting_enabled')->default(true);
            $table->integer('number_of_images')->default(1);
            // Webhook tracking
            $table->timestamp('webhook_received_at')->nullable();

            $table->timestamps();

            // Index for quick lookups
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generated_images');
    }
};
