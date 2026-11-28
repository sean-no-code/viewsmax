<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Audit log of REST calls made with a vmx_ API key — the REST twin of
// mcp_tool_invocations (see ApiKeyAuditLog).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_key_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('path');
            $table->unsignedSmallInteger('status');
            $table->json('request')->nullable();
            $table->text('response')->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_requests');
    }
};
