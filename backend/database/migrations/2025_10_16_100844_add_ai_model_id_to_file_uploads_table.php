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
        Schema::table('file_uploads', function (Blueprint $table) {
            $table->foreignId('ai_model_id')->nullable()->constrained()->onDelete('cascade');
            $table->index(['ai_model_id', 'file_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('file_uploads', function (Blueprint $table) {
            $table->dropForeign(['ai_model_id']);
            $table->dropIndex(['ai_model_id', 'file_category_id']);
            $table->dropColumn('ai_model_id');
        });
    }
};
