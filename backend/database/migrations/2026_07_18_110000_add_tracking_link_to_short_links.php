<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bridge composer shortlinks to offer attribution: a shortlink whose
 * destination is one of the user's offers carries the auto-minted
 * TrackingLink, and the redirect appends its ?trk= parameter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('short_links', function (Blueprint $table) {
            $table->foreignId('tracking_link_id')->nullable()
                ->constrained('tracking_links')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('short_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tracking_link_id');
        });
    }
};
