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
        Schema::table('script_versions', function (Blueprint $table) {
            // Change from binary to text for PostgreSQL compatibility
            $table->text('content')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('script_versions', function (Blueprint $table) {
            $table->binary('content')->change();
        });
    }
};
