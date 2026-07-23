<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create the new table
        Schema::create('tracking_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracking_event_id')->constrained('tracking_events')->cascadeOnDelete();
            $table->string('event_type')->default('conversion');
            $table->string('conversion_url');
            $table->timestamps();
        });

        // 2. Migrate existing data
        // We use raw DB queries to avoid Model logic issues during migration
        $events = DB::table('tracking_events')->get();
        foreach ($events as $event) {
            if (!empty($event->conversion_url)) {
                DB::table('tracking_goals')->insert([
                    'tracking_event_id' => $event->id,
                    'conversion_url' => $event->conversion_url,
                    'event_type' => $event->event_type ?? 'conversion',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 3. Drop old columns
        Schema::table('tracking_events', function (Blueprint $table) {
            $table->dropColumn(['conversion_url', 'event_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracking_events', function (Blueprint $table) {
            $table->string('conversion_url')->nullable(); // Nullable initially to avoid issues
            $table->string('event_type')->default('conversion');
        });

        // Restore data (simplified: takes the first goal found)
        $goals = DB::table('tracking_goals')->get();
        foreach ($goals as $goal) {
            // We can only restore one per event, so we just overwrite (imperfect reverse)
            DB::table('tracking_events')
                ->where('id', $goal->tracking_event_id)
                ->update([
                    'conversion_url' => $goal->conversion_url,
                    'event_type' => $goal->event_type
                ]);
        }
        
        // Enforce not null now? Maybe not needed for rollback safety.

        Schema::dropIfExists('tracking_goals');
    }
};
