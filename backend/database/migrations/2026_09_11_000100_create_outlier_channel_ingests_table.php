<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Add a creator channel by @handle / profile URL" support. Outlier-domain tables →
// outlier_db connection; users live on the main DB so user_id is a plain indexed
// column (cross-connection FKs are impossible).
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('outlier_db')->table('channels', function (Blueprint $table) {
            // Lowercase @handle / username without the "@" — what a user types, and
            // what CaptAPI channel endpoints take. Nullable: search-scraped YouTube
            // channels only get one when the API sends snippet.customUrl.
            $table->string('handle', 100)->nullable()->index();
            // Last time a full "recent videos" pull completed for this channel;
            // repeat adds inside the dedupe window skip the provider entirely.
            $table->timestamp('last_ingested_at')->nullable();
        });

        Schema::connection('outlier_db')->create('outlier_channel_ingests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('platform', 20);
            $table->string('input', 500);
            $table->string('handle', 100)->nullable();
            $table->foreignId('channel_id')->nullable()->constrained('channels')->nullOnDelete();
            $table->string('status', 20)->default('queued')->index();
            $table->text('error')->nullable();
            $table->unsignedInteger('videos_added')->default(0);
            // "limit" is reserved in Postgres — keep raw SQL / psql friendly.
            $table->unsignedSmallInteger('max_videos')->default(10);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('outlier_db')->dropIfExists('outlier_channel_ingests');
        Schema::connection('outlier_db')->table('channels', function (Blueprint $table) {
            $table->dropColumn(['handle', 'last_ingested_at']);
        });
    }
};
