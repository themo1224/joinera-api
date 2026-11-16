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
        Schema::table('apply_attempts', function (Blueprint $table) {
            $table->string('stop_reason')->nullable()->after('confirmation_reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('apply_attempts', function (Blueprint $table) {
            $table->dropColumn('stop_reason');
        });
    }
};
