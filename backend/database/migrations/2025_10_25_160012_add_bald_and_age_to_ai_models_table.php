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
        Schema::table('ai_models', function (Blueprint $table) {
            $table->boolean('bald')->default(false);
            $table->integer('age')->nullable();
            $table->foreignId('ai_model_type_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('ethnicity_id')->nullable()->constrained()->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropForeign(['ai_model_type_id']);
            $table->dropForeign(['ethnicity_id']);
            $table->dropColumn(['bald', 'age', 'ai_model_type_id', 'ethnicity_id']);
        });
    }
};
