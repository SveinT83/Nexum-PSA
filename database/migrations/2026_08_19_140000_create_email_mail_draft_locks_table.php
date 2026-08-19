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
        Schema::create('email_mail_draft_locks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('session_id')->nullable();
            $table->timestamp('expires_at')->index();
            $table->string('version_hash')->nullable(); // For stale-composer protection
            $table->timestamps();

            $table->unique(['conversation_id'], 'uk_email_draft_lock_conversation');

            $table->foreign('user_id')->references('id')->on('user_management');
            // conversation_id foreign key depends on whether we have email_conversations table yet.
            // Based on previous slices, we use email_mail_conversations or similar.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_mail_draft_locks');
    }
};
