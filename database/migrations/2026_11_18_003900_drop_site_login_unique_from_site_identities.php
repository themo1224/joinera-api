<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_identities', function (Blueprint $table) {
            $table->dropUnique(['site', 'site_login']);
        });
    }

    public function down(): void
    {
        Schema::table('site_identities', function (Blueprint $table) {
            $table->unique(['site', 'site_login']);
        });
    }
};
