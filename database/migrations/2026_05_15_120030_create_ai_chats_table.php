<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create technician-owned AI chat sessions.
     */
    public function up(): void
    {
        if (Schema::hasTable('ai_chats')) {
            return;
        }

        Schema::create('ai_chats', function (Blueprint $table) {
            $table->id();
            // Keep user ownership indexed without an FK so existing installs with
            // legacy user_management key definitions can still run this migration.
            $table->unsignedBigInteger('user_id')->index();
            $table->foreignId('ai_agent_id')->nullable()->constrained('ai_agents')->nullOnDelete();
            $table->string('title');
            $table->string('status')->default('open');
            $table->json('metadata')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'last_message_at']);
        });
    }

    /**
     * Drop technician chat sessions.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_chats');
    }
};
