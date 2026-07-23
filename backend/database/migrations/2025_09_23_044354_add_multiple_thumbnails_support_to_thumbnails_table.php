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
            $table->integer('number_of_thumbnails')->default(1)->after('visualizable_scene');
            $table->json('file_locations')->nullable()->after('file_location');
            $table->text('error_message')->nullable()->after('status');
            $table->timestamp('processed_at')->nullable()->after('error_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('thumbnails', function (Blueprint $table) {
            $table->dropColumn(['number_of_thumbnails', 'file_locations', 'error_message', 'processed_at']);
        });
    }
};
