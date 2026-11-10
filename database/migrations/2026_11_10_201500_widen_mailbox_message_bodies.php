<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->mediumText('body_text')->nullable()->change();
            $table->mediumText('body_html')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->text('body_text')->nullable()->change();
            $table->text('body_html')->nullable()->change();
        });
    }
};
