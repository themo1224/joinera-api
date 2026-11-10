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
        Schema::table('mailbox_identities', function (Blueprint $table) {
            $table->dropUnique(['domain']);
            $table->text('password')->nullable()->after('address');
            $table->string('provider')->default('gmx')->after('password');
            $table->timestamp('last_synced_at')->nullable()->after('state');
            $table->string('last_sync_error')->nullable()->after('last_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mailbox_identities', function (Blueprint $table) {
            $table->dropColumn(['password', 'provider', 'last_synced_at', 'last_sync_error']);
            $table->unique('domain');
        });
    }
};
