<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->timestamp('manually_completed_at')->nullable()->after('docs_failure_code');
            $table->foreignId('manually_completed_by')
                ->nullable()
                ->after('manually_completed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('manually_completed_by');
            $table->dropColumn('manually_completed_at');
        });
    }
};
