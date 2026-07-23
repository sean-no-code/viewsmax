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
        Schema::table('thumbnails', function (Blueprint $table) {
            $table->foreignId('negative_prompt_id')->nullable()->constrained('prompts')->onDelete('set null');
            $table->foreignId('style_prompt_id')->nullable()->constrained('prompts')->onDelete('set null');
            $table->foreignId('general_prompt_id')->nullable()->constrained('prompts')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('thumbnails', function (Blueprint $table) {
            $table->dropForeign(['negative_prompt_id']);
            $table->dropForeign(['style_prompt_id']);
            $table->dropForeign(['general_prompt_id']);
            $table->dropColumn(['negative_prompt_id', 'style_prompt_id', 'general_prompt_id']);
        });
    }
};
