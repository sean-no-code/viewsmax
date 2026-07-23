<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('thumbnails', function (Blueprint $table) {
            // Type column to distinguish between regular thumbnails and copy thumbnails
            $table->string('type', 30)->default('thumbnail')->after('id');

            // Copy-thumbnail-specific columns (nullable for regular thumbnails)
            $table->string('source_image_path')->nullable()->after('comfy_prompt_id');
            $table->string('source_image_url')->nullable()->after('source_image_path');
            $table->string('result_image_path')->nullable()->after('source_image_url');
            $table->json('processing_log')->nullable()->after('error_message');
            $table->text('extracted_expression')->nullable()->after('processing_log');
            $table->text('transformed_prompt')->nullable()->after('extracted_expression');
            $table->integer('resolution_width')->nullable()->after('transformed_prompt');
            $table->integer('resolution_height')->nullable()->after('resolution_width');
            $table->boolean('dw_pose_enabled')->nullable()->after('resolution_height');
            $table->foreignId('generated_image_id')->nullable()->after('ai_model_id')
                ->constrained('generated_images')->nullOnDelete();

            $table->string('reference_image_path')->nullable()->after('source_image_url');
            // Index for type-based queries
            $table->index('type');
        });

        // Migrate existing copy_thumbnails data into thumbnails
        if (Schema::hasTable('copy_thumbnails')) {
            $copyThumbnails = DB::table('copy_thumbnails')->get();

            foreach ($copyThumbnails as $copy) {
                DB::table('thumbnails')->insert([
                    'type' => 'copy_thumbnail',
                    'user_id' => $copy->user_id,
                    'description' => '',
                    'status' => $copy->status,
                    'comfy_prompt_id' => $copy->comfy_prompt_id,
                    'error_message' => $copy->error_message,
                    'source_image_path' => $copy->source_image_path,
                    'source_image_url' => $copy->source_image_url ?? null,
                    'result_image_path' => $copy->result_image_path,
                    'processing_log' => $copy->processing_log,
                    'extracted_expression' => $copy->extracted_expression ?? null,
                    'transformed_prompt' => $copy->transformed_prompt ?? null,
                    'resolution_width' => $copy->resolution_width,
                    'resolution_height' => $copy->resolution_height,
                    'dw_pose_enabled' => $copy->dw_pose_enabled,
                    'ai_model_id' => $copy->ai_model_id,
                    'generated_image_id' => $copy->generated_image_id ?? null,
                    'negative_prompt_id' => $copy->negative_prompt_id,
                    'style_prompt_id' => $copy->style_prompt_id,
                    'general_prompt_id' => $copy->general_prompt_id,
                    'reference_image_path' => $copy->reference_image_path,
                    'created_at' => $copy->created_at,
                    'updated_at' => $copy->updated_at,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('thumbnails', function (Blueprint $table) {
            // Remove the foreign key first
            $table->dropForeign(['generated_image_id']);

            $table->dropIndex(['type']);
            $table->dropColumn([
                'type',
                'source_image_path',
                'source_image_url',
                'result_image_path',
                'processing_log',
                'extracted_expression',
                'transformed_prompt',
                'resolution_width',
                'resolution_height',
                'dw_pose_enabled',
                'generated_image_id',
                'reference_image_path',
            ]);
        });
    }
};
