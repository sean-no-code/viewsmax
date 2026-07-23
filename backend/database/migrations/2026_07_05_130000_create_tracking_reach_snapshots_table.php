<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily cumulative view-count per tracking link, so the analytics overview chart
 * can plot a "views" line over time (day-over-day deltas). The reach refresh job
 * upserts one row per link per day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_reach_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracking_link_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->unsignedBigInteger('view_count')->default(0);
            $table->timestamps();

            $table->unique(['tracking_link_id', 'snapshot_date']);
            $table->index('snapshot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_reach_snapshots');
    }
};
