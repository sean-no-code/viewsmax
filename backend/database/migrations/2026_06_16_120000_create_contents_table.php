<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `contents` table backs the Content API: a piece of content (long-form
     * body text + optional media file) that a user posts and can attach to an Offer.
     */
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            // Offers live in the (legacy-named) tracking_events table via the Offer model.
            $table->foreignId('offer_id')->nullable()
                ->constrained('tracking_events')->nullOnDelete();
            $table->string('title');
            $table->longText('body')->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_filename')->nullable();
            $table->string('media_mime')->nullable();
            $table->unsignedBigInteger('media_size')->nullable();
            $table->string('status')->default('draft'); // draft|published
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
