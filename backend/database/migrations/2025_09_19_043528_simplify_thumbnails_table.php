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
            // Add the simple file_location column
            $table->string('file_location')->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('thumbnails', function (Blueprint $table) {
            // Add back the complex columns
            $table->string('status')->default('pending');
            $table->json('file_locations')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            
            // Drop the simple file_location column
            $table->dropColumn('file_location');
        });
    }
};
