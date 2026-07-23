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
        Schema::table('videos', function (Blueprint $table) {
            // Drop the existing foreign key constraint
            $table->dropForeign(['channel_id']);
        });

        Schema::table('videos', function (Blueprint $table) {
            // Re-add the foreign key with cascade delete
            $table->foreign('channel_id')
                ->references('id')
                ->on('channels')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // Drop the cascade foreign key
            $table->dropForeign(['channel_id']);
        });

        Schema::table('videos', function (Blueprint $table) {
            // Re-add the original set null foreign key
            $table->foreign('channel_id')
                ->references('id')
                ->on('channels')
                ->onDelete('set null');
        });
    }
};
