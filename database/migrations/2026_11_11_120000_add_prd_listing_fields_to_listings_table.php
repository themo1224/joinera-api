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
        Schema::table('listings', function (Blueprint $table) {
            $table->string('apply_url')->nullable()->after('url');
            $table->text('description')->nullable()->after('apply_url');
            $table->string('language_requirement')->nullable()->after('description');
            $table->string('education_requirement')->nullable()->after('language_requirement');
            $table->string('country')->nullable()->after('education_requirement');
            $table->timestamp('posted_at')->nullable()->after('country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn([
                'apply_url',
                'description',
                'language_requirement',
                'education_requirement',
                'country',
                'posted_at',
            ]);
        });
    }
};
