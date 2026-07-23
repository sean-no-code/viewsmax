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
            // Remove the visualizable_scene column
            $table->dropColumn('visualizable_scene');
            
            // Add the prompt column
            $table->text('prompt')->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('thumbnails', function (Blueprint $table) {
            // Add back the visualizable_scene column
            $table->text('visualizable_scene')->nullable()->after('description');
            
            // Remove the prompt column
            $table->dropColumn('prompt');
        });
    }
};
