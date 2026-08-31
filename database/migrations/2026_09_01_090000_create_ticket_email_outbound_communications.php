<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_email_outbound_communications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('ticket_id');
            $table->foreignId('email_ticket_conversation_link_id');
            $table->foreignId('email_account_id');
            $table->foreignId('email_conversation_id')->nullable();
            $table->foreignId('source_email_message_id');
            $table->foreignId('source_email_mailbox_placement_id');
            $table->foreignId('ticket_message_id')->nullable();
            $table->foreignId('email_composer_draft_id');
            $table->foreignId('email_outbound_submission_id')->nullable();
            $table->foreignId('reconciled_sent_email_message_id')->nullable();
            $table->foreignId('reconciled_sent_email_mailbox_placement_id')->nullable();
            $table->string('operation_kind', 32);
            $table->string('audience', 32);
            $table->string('state', 32)->index();
            $table->string('recipient_fingerprint', 64);
            $table->string('thread_fingerprint', 64);
            $table->string('subject_fingerprint', 64);
            $table->string('source_fingerprint', 64);
            $table->string('attachment_manifest_hash', 64)->nullable();
            $table->string('signature_fingerprint', 64)->nullable();
            $table->unsignedBigInteger('provider_binding_version');
            $table->string('idempotency_key', 120);
            $table->unsignedBigInteger('actor_id')->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('safe_reason_code', 96)->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            $table->unique('email_outbound_submission_id', 'ticket_email_comm_submission_unique');
            $table->unique(['ticket_id', 'idempotency_key'], 'ticket_email_comm_ticket_idempotency_unique');
            $table->index(['ticket_id', 'state'], 'ticket_email_comm_ticket_state_index');
            $table->index(['email_composer_draft_id', 'state'], 'ticket_email_comm_draft_state_index');
            $table->foreign('ticket_id', 'ticket_email_comm_ticket_fk')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('email_ticket_conversation_link_id', 'ticket_email_comm_link_fk')->references('id')->on('email_ticket_conversation_links')->restrictOnDelete();
            $table->foreign('email_account_id', 'ticket_email_comm_account_fk')->references('id')->on('email_accounts')->restrictOnDelete();
            $table->foreign('email_conversation_id', 'ticket_email_comm_conversation_fk')->references('id')->on('email_conversations')->nullOnDelete();
            $table->foreign('source_email_message_id', 'ticket_email_comm_source_message_fk')->references('id')->on('email_messages')->restrictOnDelete();
            $table->foreign('source_email_mailbox_placement_id', 'ticket_email_comm_source_placement_fk')->references('id')->on('email_mailbox_placements')->restrictOnDelete();
            $table->foreign('ticket_message_id', 'ticket_email_comm_ticket_message_fk')->references('id')->on('ticket_messages')->nullOnDelete();
            $table->foreign('email_composer_draft_id', 'ticket_email_comm_draft_fk')->references('id')->on('email_composer_drafts')->restrictOnDelete();
            $table->foreign('email_outbound_submission_id', 'ticket_email_comm_submission_fk')->references('id')->on('email_outbound_submissions')->nullOnDelete();
            $table->foreign('reconciled_sent_email_message_id', 'ticket_email_comm_sent_message_fk')->references('id')->on('email_messages')->nullOnDelete();
            $table->foreign('reconciled_sent_email_mailbox_placement_id', 'ticket_email_comm_sent_placement_fk')->references('id')->on('email_mailbox_placements')->nullOnDelete();
            $table->foreign('actor_id', 'ticket_email_comm_actor_fk')->references('id')->on('user_management')->restrictOnDelete();
        });

        Schema::create('ticket_email_outbound_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_email_outbound_communication_id');
            $table->string('event_type', 48)->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('safe_reason_code', 96)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('ticket_email_outbound_communication_id', 'ticket_email_event_communication_fk')
                ->references('id')->on('ticket_email_outbound_communications')->cascadeOnDelete();
            $table->foreign('actor_id', 'ticket_email_event_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('ticket_email_outbound_events')
                && \Illuminate\Support\Facades\DB::table('ticket_email_outbound_events')->exists())
            || (Schema::hasTable('ticket_email_outbound_communications')
                && \Illuminate\Support\Facades\DB::table('ticket_email_outbound_communications')->exists())) {
            throw new \RuntimeException(
                'Ticket Email outbound communication evidence must be preserved before rollback.',
            );
        }

        Schema::dropIfExists('ticket_email_outbound_events');
        Schema::dropIfExists('ticket_email_outbound_communications');
    }
};
