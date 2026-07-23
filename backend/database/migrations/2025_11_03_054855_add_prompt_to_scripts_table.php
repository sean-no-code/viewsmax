<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->text('prompt')->nullable()->after('title');
        });
        
        // Set default prompt for existing records based on title
        DB::statement("UPDATE scripts SET prompt = CONCAT('Create an engaging video script about: ', COALESCE(title, 'this topic')) WHERE prompt IS NULL OR prompt = ''");
        
        // Now make it required
        Schema::table('scripts', function (Blueprint $table) {
            $table->text('prompt')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->dropColumn('prompt');
        });
    }
};
