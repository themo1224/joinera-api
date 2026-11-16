<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_access_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('site');
            $table->foreignId('site_identity_id')->constrained('site_identities')->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 500);
            $table->timestamps();

            $table->index(['actor_id', 'created_at']);
            $table->index(['user_id', 'site']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_access_audits');
    }
};
