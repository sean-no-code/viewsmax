<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->timestamp('dispatched_at')->index();
            // Stamped by QueueHeartbeatJob when a worker runs it.
            $table->timestamp('processed_at')->nullable()->index();
            // Alerting state: the row that triggered a "stuck" email, and when
            // the matching "recovered" email went out.
            $table->timestamp('alerted_at')->nullable()->index();
            $table->timestamp('recovered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_heartbeats');
    }
};
