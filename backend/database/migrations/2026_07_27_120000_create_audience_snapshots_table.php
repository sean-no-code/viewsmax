<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily follower/subscriber count per connected social account, so the Audience
 * Growth page can plot a follower line over time (day-over-day deltas). The
 * audience:refresh job upserts one row per account per day. Mirrors
 * tracking_reach_snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audience_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->unsignedBigInteger('follower_count')->default(0);
            $table->timestamps();

            $table->unique(['social_account_id', 'snapshot_date']);
            $table->index('snapshot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_snapshots');
    }
};
