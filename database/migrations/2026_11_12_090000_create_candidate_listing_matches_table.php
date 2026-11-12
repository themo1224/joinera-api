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
        Schema::create('candidate_listing_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('hard_skip_reason')->nullable();
            $table->json('soft_signals')->nullable();
            $table->unsignedInteger('rank_score')->nullable();
            $table->timestamp('ranked_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->foreignId('force_queued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('force_queued_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'listing_id']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidate_listing_matches');
    }
};
