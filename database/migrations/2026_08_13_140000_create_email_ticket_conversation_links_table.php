<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_ticket_conversation_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id');
            $table->foreignId('email_message_id');
            $table->foreignId('email_mailbox_placement_id')->nullable();
            $table->foreignId('account_id')->nullable();
            $table->unsignedBigInteger('linked_by')->nullable()->index();
            $table->string('conversation_key', 160)->index();
            $table->string('relationship_role', 40)->default('secondary')->index();
            $table->string('audience', 40)->default('customer')->index();
            $table->string('status', 40)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('unlinked_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['ticket_id', 'email_message_id', 'status'],
                'email_ticket_conv_ticket_message_status_unique',
            );
            $table->index(['conversation_key', 'status'], 'email_ticket_conv_key_status_index');
            $table->index(['email_message_id', 'status'], 'email_ticket_conv_message_status_index');
            $table->foreign('ticket_id', 'email_ticket_conv_ticket_fk')
                ->references('id')
                ->on('tickets')
                ->cascadeOnDelete();
            $table->foreign('email_message_id', 'email_ticket_conv_message_fk')
                ->references('id')
                ->on('email_messages')
                ->cascadeOnDelete();
            $table->foreign('email_mailbox_placement_id', 'email_ticket_conv_placement_fk')
                ->references('id')
                ->on('email_mailbox_placements')
                ->nullOnDelete();
            $table->foreign('account_id', 'email_ticket_conv_account_fk')
                ->references('id')
                ->on('email_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_ticket_conversation_links');
    }
};
