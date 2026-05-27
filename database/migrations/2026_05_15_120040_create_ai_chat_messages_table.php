<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store chat messages for technician AI sessions.
     */
    public function up(): void
    {
        if (Schema::hasTable('ai_chat_messages')) {
            return;
        }

        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_chat_id')->constrained('ai_chats')->cascadeOnDelete();
            // Message authors are resolved in application code; no FK keeps this
            // compatible with older user table definitions.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('role');
            $table->longText('body');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['ai_chat_id', 'created_at']);
        });
    }

    /**
     * Drop chat messages.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
    }
};
