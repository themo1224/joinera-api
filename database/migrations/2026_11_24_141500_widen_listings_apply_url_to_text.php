<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company apply URLs from Azubiyo exit interstitials (and similar boards)
 * often exceed varchar(255) once query/tracking params are included.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE listings ALTER COLUMN apply_url TYPE TEXT');

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE listings MODIFY apply_url TEXT NULL');

            return;
        }

        // sqlite / others: recreate via Laravel change() when available.
        Schema::table('listings', function ($table) {
            $table->text('apply_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Irreversible safely for long URLs already stored.
    }
};
