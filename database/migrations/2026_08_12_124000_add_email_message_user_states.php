<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_message_user_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_message_id')
                ->constrained('email_messages')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('user_management')
                ->cascadeOnDelete();
            $table->foreignId('last_opened_placement_id')
                ->nullable()
                ->constrained('email_mailbox_placements')
                ->nullOnDelete();
            $table->boolean('is_unread')->default(true)->index();
            $table->unsignedInteger('opened_count')->default(0);
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamp('marked_read_at')->nullable();
            $table->timestamp('marked_unread_at')->nullable();
            $table->timestamps();

            $table->unique(['email_message_id', 'user_id'], 'email_message_user_states_unique');
            $table->index(['user_id', 'is_unread'], 'email_message_user_states_user_unread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_message_user_states');
    }
};
