<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store global AI memory and retention settings.
     */
    public function up(): void
    {
        if (Schema::hasTable('ai_system_settings')) {
            return;
        }

        Schema::create('ai_system_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('context_message_limit')->default(20);
            $table->unsignedSmallInteger('chat_retention_days')->default(90);
            $table->unsignedSmallInteger('delete_empty_chats_after_days')->default(7);
            $table->unsignedSmallInteger('delete_failed_pending_after_hours')->default(24);
            $table->boolean('cleanup_enabled')->default(true);
            $table->timestamp('last_cleanup_at')->nullable();
            $table->json('last_cleanup_summary')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Remove global AI settings.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_system_settings');
    }
};
