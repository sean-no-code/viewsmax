<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('outlier_db')->create('transcripts', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20);            // youtube | tiktok | instagram
            $table->text('source_url');                // the requested video/reel URL
            $table->string('url_hash', 64);            // sha256(platform|url) — cache key
            $table->text('text')->nullable();          // full transcript text
            $table->json('segments')->nullable();      // [{ text, startMs, endMs }]
            $table->string('language', 16)->nullable();
            $table->timestamp('provider_fetched_at')->nullable(); // CaptAPI data.fetchedAt
            $table->string('request_id')->nullable();
            $table->unsignedInteger('credits_used')->nullable();
            $table->timestamps();

            // One cached transcript per platform+URL — re-requests read from here, not the API.
            $table->unique(['platform', 'url_hash']);
        });
    }

    public function down(): void
    {
        Schema::connection('outlier_db')->dropIfExists('transcripts');
    }
};
