<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // outlier_db is a SEPARATE connection: the migrations ledger (main DB)
        // can say this never ran while the outlier DB already has the tables
        // (provisioned by another environment). Guard every create so the run
        // is idempotent instead of dying on "relation already exists".
        $schema = Schema::connection('outlier_db');

        if (! $schema->hasTable('search_terms')) {
            $schema->create('search_terms', function (Blueprint $table) {
                $table->id();
                $table->string('term')->unique();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('search_terms_requests')) {
            $schema->create('search_terms_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id'); // Just an ID, no FK constraint
                $table->foreignId('term_id')->constrained('search_terms')->onDelete('cascade'); // This works because search_terms is in the same DB
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! $schema->hasTable('terms_data_fetch')) {
            $schema->create('terms_data_fetch', function (Blueprint $table) {
                $table->id();
                $table->foreignId('term_id')->constrained('search_terms')->onDelete('cascade');
                $table->timestamp('fetched_at')->useCurrent();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terms_data_fetch');
        Schema::dropIfExists('search_terms_requests');
        Schema::dropIfExists('search_terms');
    }
};
