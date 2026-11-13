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
        Schema::create('doc_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // cv | cover
            $table->string('rule'); // cover_only | cv_and_cover
            $table->boolean('cache_hit')->default(false);
            $table->string('disk');
            $table->string('path');
            $table->string('mime')->default('application/pdf');
            $table->unsignedBigInteger('size');
            $table->string('llm_model')->nullable();
            $table->unsignedInteger('llm_prompt_tokens')->nullable();
            $table->unsignedInteger('llm_completion_tokens')->nullable();
            $table->unsignedInteger('llm_total_tokens')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'kind']);
            $table->index('application_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_artifacts');
    }
};
