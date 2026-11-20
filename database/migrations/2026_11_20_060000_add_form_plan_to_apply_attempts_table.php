<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apply_attempts', function (Blueprint $table) {
            $table->json('form_plan')->nullable()->after('stop_reason');
        });
    }

    public function down(): void
    {
        Schema::table('apply_attempts', function (Blueprint $table) {
            $table->dropColumn('form_plan');
        });
    }
};
