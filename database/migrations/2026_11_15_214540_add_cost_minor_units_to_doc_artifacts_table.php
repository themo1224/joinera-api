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
        Schema::table('doc_artifacts', function (Blueprint $table) {
            $table->unsignedInteger('cost_minor_units')->nullable()->after('llm_total_tokens');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doc_artifacts', function (Blueprint $table) {
            $table->dropColumn('cost_minor_units');
        });
    }
};
