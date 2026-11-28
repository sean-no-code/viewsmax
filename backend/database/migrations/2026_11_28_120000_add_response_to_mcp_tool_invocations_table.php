<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The audit log kept what a tool was asked to do but not what it answered,
// so "what did the AI see" couldn't be answered from the data.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_tool_invocations', function (Blueprint $table) {
            $table->text('response')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_tool_invocations', function (Blueprint $table) {
            $table->dropColumn('response');
        });
    }
};
