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
        Schema::create('mailbox_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_identity_id')->constrained('mailbox_identities')->cascadeOnDelete();
            $table->string('provider_message_id');
            $table->string('direction');
            $table->string('from_address');
            $table->string('to_address');
            $table->string('subject')->default('');
            $table->text('body_text')->nullable();
            $table->text('body_html')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('in_reply_to_message_id')->nullable()->constrained('mailbox_messages')->nullOnDelete();
            $table->timestamps();

            $table->unique(['mailbox_identity_id', 'provider_message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailbox_messages');
    }
};
