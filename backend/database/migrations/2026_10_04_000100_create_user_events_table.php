<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user product events that no other table records. Today that is only
 * "viewed an outlier breakdown": breakdowns are per video (outlier_db) with no
 * user_id, so nothing else says who looked at one. Read by the admin's
 * activation funnel. Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_name');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'event_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_events');
    }
};
