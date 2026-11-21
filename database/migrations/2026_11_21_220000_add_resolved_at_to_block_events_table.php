<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('block_events', function (Blueprint $table) {
            // Plan 25 — open pause is resolved_at IS NULL (works without SiteIdentity).
            $table->timestamp('resolved_at')->nullable()->after('observed_kind');
            $table->index('resolved_at');
        });

        // Historical rows: treat as already cleared so ops is not flooded.
        DB::table('block_events')->whereNull('resolved_at')->update([
            'resolved_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('block_events', function (Blueprint $table) {
            $table->dropIndex(['resolved_at']);
            $table->dropColumn('resolved_at');
        });
    }
};
