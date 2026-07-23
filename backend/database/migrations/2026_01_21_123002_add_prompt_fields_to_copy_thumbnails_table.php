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
        Schema::table('copy_thumbnails', function (Blueprint $table) {
            $table->unsignedBigInteger('negative_prompt_id')->nullable()->after('ai_model_id');
            $table->unsignedBigInteger('style_prompt_id')->nullable()->after('negative_prompt_id');
            $table->unsignedBigInteger('general_prompt_id')->nullable()->after('style_prompt_id');
            
            $table->foreign('negative_prompt_id')->references('id')->on('prompts')->onDelete('set null');
            $table->foreign('style_prompt_id')->references('id')->on('prompts')->onDelete('set null');
            $table->foreign('general_prompt_id')->references('id')->on('prompts')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('copy_thumbnails', function (Blueprint $table) {
            $table->dropForeign(['negative_prompt_id']);
            $table->dropForeign(['style_prompt_id']);
            $table->dropForeign(['general_prompt_id']);
            
            $table->dropColumn(['negative_prompt_id', 'style_prompt_id', 'general_prompt_id']);
        });
    }
};
