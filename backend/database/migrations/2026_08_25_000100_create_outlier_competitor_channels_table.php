<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-user "competitor" channels picked from the outlier database. Outlier-domain
// table → outlier_db connection; users live on the main DB so user_id is a plain
// indexed column (cross-connection FKs are impossible).
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('outlier_db')->create('outlier_competitor_channels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('outlier_db')->dropIfExists('outlier_competitor_channels');
    }
};
