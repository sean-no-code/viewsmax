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
        // First, update any NULL titles to a default value
        DB::table('scripts')->whereNull('title')->update(['title' => 'Untitled Script']);
        
        // Then make the column required
        Schema::table('scripts', function (Blueprint $table) {
            $table->string('title')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->string('title')->nullable()->change();
        });
    }
};
