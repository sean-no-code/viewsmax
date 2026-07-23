<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user Beehiiv API-key connection. The api_key is stored encrypted at rest
 * (Eloquent 'encrypted' cast on the model) and never returned to the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beehiiv_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('api_key'); // encrypted via the model cast
            $table->string('publication_id')->nullable();
            $table->string('publication_name')->nullable();
            $table->string('status')->default('connected');
            $table->text('last_error')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beehiiv_connections');
    }
};
