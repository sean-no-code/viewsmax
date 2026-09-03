<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'outlier_db';

    public function up(): void
    {
        $schema = Schema::connection('outlier_db');

        // Idempotent: skip when the rename already happened on this outlier DB
        // (tables already live as channels/videos).
        if (! $schema->hasTable('outlier_videos')) {
            return;
        }

        // 1. Drop FK before any renames
        $schema->table('outlier_videos', function (Blueprint $table) {
            $table->dropForeign(['outlier_channel_id']);
        });

        // 2. Rename the FK column
        $schema->table('outlier_videos', function (Blueprint $table) {
            $table->renameColumn('outlier_channel_id', 'channel_id');
        });

        // 3. Rename tables
        $schema->rename('outlier_channels', 'channels');
        $schema->rename('outlier_videos', 'videos');

        // 4. Re-add FK pointing to renamed table with renamed column
        $schema->table('videos', function (Blueprint $table) {
            $table->foreign('channel_id')
                  ->references('id')
                  ->on('channels')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('outlier_db');

        $schema->table('videos', function (Blueprint $table) {
            $table->dropForeign(['channel_id']);
        });

        $schema->table('videos', function (Blueprint $table) {
            $table->renameColumn('channel_id', 'outlier_channel_id');
        });

        $schema->rename('videos', 'outlier_videos');
        $schema->rename('channels', 'outlier_channels');

        $schema->table('outlier_videos', function (Blueprint $table) {
            $table->foreign('outlier_channel_id')
                  ->references('id')
                  ->on('outlier_channels')
                  ->onDelete('cascade');
        });
    }
};
