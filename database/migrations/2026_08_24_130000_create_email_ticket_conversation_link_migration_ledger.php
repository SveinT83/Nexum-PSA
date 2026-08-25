<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_ticket_conversation_link_migration_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('status', 24)->index();
            $table->unsignedInteger('item_cap');
            $table->unsignedInteger('candidate_count')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('already_mapped_count')->default(0);
            $table->unsignedInteger('conflict_count')->default(0);
            $table->unsignedInteger('applied_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->char('scope_fingerprint', 64);
            $table->string('error_code', 80)->nullable();
            $table->timestamp('previewed_at');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('requested_by', 'em_ticket_link_migration_run_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->index(
                ['status', 'previewed_at'],
                'em_ticket_link_migration_run_status_ix',
            );
        });

        Schema::create('email_ticket_conversation_link_migration_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('email_message_id');
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('email_mailbox_placement_id')->nullable();
            $table->unsignedBigInteger('email_conversation_id')->nullable();
            $table->unsignedBigInteger('ticket_message_id')->nullable();
            $table->unsignedBigInteger('applied_link_id')->nullable();
            $table->string('status', 32)->index();
            $table->string('reason_code', 80)->nullable();
            $table->string('audience', 24)->nullable();
            $table->char('base_fingerprint', 64);
            $table->char('source_fingerprint', 64);
            $table->json('evidence');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->foreign('run_id', 'em_ticket_link_migration_item_run_fk')
                ->references('id')->on('email_ticket_conversation_link_migration_runs')
                ->cascadeOnDelete();
            $table->foreign('applied_link_id', 'em_ticket_link_migration_item_link_fk')
                ->references('id')->on('email_ticket_conversation_links')->nullOnDelete();
            $table->unique(
                ['run_id', 'email_message_id'],
                'em_ticket_link_migration_item_message_uq',
            );
            $table->index(
                ['run_id', 'status', 'id'],
                'em_ticket_link_migration_item_progress_ix',
            );
            $table->index(
                ['email_conversation_id', 'ticket_id'],
                'em_ticket_link_migration_item_target_ix',
            );
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('email_ticket_conversation_link_migration_items')
                && DB::table('email_ticket_conversation_link_migration_items')->exists())
            || (Schema::hasTable('email_ticket_conversation_link_migration_runs')
                && DB::table('email_ticket_conversation_link_migration_runs')->exists())) {
            throw new \RuntimeException(
                'Refusing to remove Email/Ticket relationship migration evidence.',
            );
        }

        Schema::dropIfExists('email_ticket_conversation_link_migration_items');
        Schema::dropIfExists('email_ticket_conversation_link_migration_runs');
    }
};
