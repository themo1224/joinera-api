<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('site');
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->string('observed_kind');
            $table->timestamps();

            $table->index(['user_id', 'site']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_events');
    }
};
