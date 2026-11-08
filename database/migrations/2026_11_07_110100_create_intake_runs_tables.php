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
        Schema::create('intake_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('intake_run_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_run_id')->constrained('intake_runs')->cascadeOnDelete();
            $table->string('site');
            $table->string('status');
            $table->text('error')->nullable();
            $table->unsignedInteger('listings_created')->default(0);
            $table->timestamps();

            $table->unique(['intake_run_id', 'site']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intake_run_sites');
        Schema::dropIfExists('intake_runs');
    }
};
