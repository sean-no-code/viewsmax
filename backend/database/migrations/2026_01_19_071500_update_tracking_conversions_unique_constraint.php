<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Disable transaction wrapping so that caught exceptions don't abort the connection in Postgres
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Try to drop explicit named constraint (Postgres likely name)
        try {
            Schema::table('tracking_conversions', function (Blueprint $table) {
                $table->dropUnique('tracking_conversions_tracking_visitor_id_tracking_event_id_uniq');
            });
        } catch (\Exception $e) {
            // Ignore if missing
        }

        // 2. Try to drop Laravel auto-named constraint (array syntax)
        try {
            Schema::table('tracking_conversions', function (Blueprint $table) {
                $table->dropUnique(['tracking_visitor_id', 'tracking_event_id']); 
            });
        } catch (\Exception $e) {
            // Ignore if missing
        }

        // 3. Add new unique constraint
        Schema::table('tracking_conversions', function (Blueprint $table) {
            // Check if our new constraint already exists to avoid duplication error (if re-running)
            // But Schema::table doesn't let us easily check here. 
            // We'll rely on idempotency or try-catch here too if we want to be super safe.
            // But usually 'up' assumes clean state or we accept failure if it exists.
            // However, to be robust against partial runs:
            try {
                 $table->unique(['tracking_visitor_id', 'tracking_event_id', 'event_type'], 'tracking_conversions_unique_v_e_t');
            } catch (\Exception $e) {
                // Already exists probably
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracking_conversions', function (Blueprint $table) {
            $table->dropUnique('tracking_conversions_unique_v_e_t');
            $table->unique(['tracking_visitor_id', 'tracking_event_id'], 'tracking_conversions_visitor_event_unique'); // Explicitly named to avoid length issues
        });
    }
};
