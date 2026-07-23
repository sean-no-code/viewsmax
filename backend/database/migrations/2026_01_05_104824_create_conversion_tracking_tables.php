<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. tracking_events (Parent Campaign)
        if (!Schema::hasTable('tracking_events')) {
            Schema::create('tracking_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('name')->nullable();
                $table->string('landing_page_url');
                $table->string('conversion_url');
                $table->string('event_type')->default('conversion'); // conversion, call-booked, etc
                $table->decimal('conversion_value', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        // 2. tracking_links (Traffic Sources)
        if (!Schema::hasTable('tracking_links')) {
            Schema::create('tracking_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tracking_event_id')->constrained()->cascadeOnDelete();
                $table->foreignId('video_id')->nullable()->constrained('videos')->nullOnDelete(); 
                $table->string('placement')->default('video'); // video, email, tiktok
                $table->string('name')->nullable();
                $table->string('parameter_id')->unique()->index(); // The 'trk' hash
                $table->timestamps();
            });
        }

        // 3. tracking_visitors (The People)
        if (!Schema::hasTable('tracking_visitors')) {
            Schema::create('tracking_visitors', function (Blueprint $table) {
                $table->id();
                $table->uuid('visitor_id')->unique(); // Public Cookie ID
                $table->string('ip_address')->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();
            });
        }

        // 4. tracking_clicks (The Traffic)
        if (!Schema::hasTable('tracking_clicks')) {
            Schema::create('tracking_clicks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tracking_visitor_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tracking_link_id')->constrained()->cascadeOnDelete();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        // 5. tracking_conversions (The Logs)
        if (!Schema::hasTable('tracking_conversions')) {
            Schema::create('tracking_conversions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tracking_visitor_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tracking_event_id')->constrained()->cascadeOnDelete(); // Point to Parent
                $table->string('event_type')->default('conversion');
                $table->decimal('value', 10, 2)->default(0);
                $table->timestamps();

                // Enforce one conversion per visitor per event
                $table->unique(['tracking_visitor_id', 'tracking_event_id'], 'tracking_conversions_visitor_event_unique');
            });
        } else {
            // If table exists but unique index is missing (our current state)
            Schema::table('tracking_conversions', function (Blueprint $table) {
                try {
                    $table->unique(['tracking_visitor_id', 'tracking_event_id'], 'tracking_conversions_visitor_event_unique');
                } catch (\Exception $e) {
                    // Already exists
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_conversions');
        Schema::dropIfExists('tracking_clicks');
        Schema::dropIfExists('tracking_visitors');
        Schema::dropIfExists('tracking_links');
        Schema::dropIfExists('tracking_events');
    }
};
