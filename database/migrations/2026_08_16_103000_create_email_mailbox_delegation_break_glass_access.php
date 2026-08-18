<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_mailbox_delegations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_account_id');
            $table->foreignId('owner_id')->nullable();
            $table->foreignId('delegate_id')->nullable();
            $table->boolean('can_view')->default(false);
            $table->boolean('can_organize')->default(false);
            $table->boolean('can_send')->default(false);
            $table->boolean('can_view_raw_source')->default(false);
            $table->text('reason');
            $table->dateTime('starts_at')->index();
            $table->dateTime('expires_at')->index();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('revoked_by')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->dateTime('revoked_at')->nullable()->index();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('email_account_id', 'em_delegate_account_fk')
                ->references('id')->on('email_accounts')->cascadeOnDelete();
            $table->foreign('owner_id', 'em_delegate_owner_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('delegate_id', 'em_delegate_user_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('created_by', 'em_delegate_creator_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('revoked_by', 'em_delegate_revoker_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->index(
                ['email_account_id', 'delegate_id', 'revoked_at', 'starts_at', 'expires_at'],
                'em_delegate_effective_ix',
            );
        });

        Schema::create('email_break_glass_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_account_id');
            $table->foreignId('actor_id')->nullable();
            $table->boolean('can_view_content')->default(false);
            $table->boolean('can_search')->default(false);
            $table->boolean('can_download_attachments')->default(false);
            $table->boolean('can_view_raw_source')->default(false);
            $table->text('reason');
            $table->dateTime('starts_at')->index();
            $table->dateTime('expires_at')->index();
            $table->foreignId('revoked_by')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->dateTime('revoked_at')->nullable()->index();
            $table->dateTime('owner_notification_sent_at')->nullable();
            $table->dateTime('security_notification_sent_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('email_account_id', 'em_break_glass_account_fk')
                ->references('id')->on('email_accounts')->cascadeOnDelete();
            $table->foreign('actor_id', 'em_break_glass_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('revoked_by', 'em_break_glass_revoker_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->index(
                ['email_account_id', 'actor_id', 'revoked_at', 'starts_at', 'expires_at'],
                'em_break_glass_effective_ix',
            );
        });

        Schema::create('email_mailbox_access_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_account_id');
            $table->foreignId('actor_id')->nullable();
            $table->foreignId('affected_user_id')->nullable();
            $table->foreignId('email_mailbox_delegation_id')->nullable();
            $table->foreignId('email_break_glass_access_id')->nullable();
            $table->string('event_type', 64);
            $table->string('operation', 64)->nullable();
            $table->string('resource_type', 64)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('reason_code', 80)->nullable();
            $table->json('metadata_json')->nullable();
            $table->char('idempotency_key', 64)->unique();
            $table->dateTime('occurred_at')->index();

            $table->foreign('email_account_id', 'em_access_event_account_fk')
                ->references('id')->on('email_accounts')->restrictOnDelete();
            $table->foreign('actor_id', 'em_access_event_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('affected_user_id', 'em_access_event_affected_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('email_mailbox_delegation_id', 'em_access_event_delegate_fk')
                ->references('id')->on('email_mailbox_delegations')->nullOnDelete();
            $table->foreign('email_break_glass_access_id', 'em_access_event_break_glass_fk')
                ->references('id')->on('email_break_glass_accesses')->nullOnDelete();
            $table->index(
                ['email_account_id', 'occurred_at', 'id'],
                'em_access_event_account_time_ix',
            );
            $table->index(
                ['actor_id', 'occurred_at', 'id'],
                'em_access_event_actor_time_ix',
            );
            $table->index(
                ['event_type', 'occurred_at', 'id'],
                'em_access_event_type_time_ix',
            );
        });
    }

    public function down(): void
    {
        foreach ([
            'email_mailbox_access_events',
            'email_break_glass_accesses',
            'email_mailbox_delegations',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new \RuntimeException(
                    'Mailbox access history exists. Revoke active access and complete an explicit retention/export decision before dropping it.',
                );
            }
        }

        Schema::dropIfExists('email_mailbox_access_events');
        Schema::dropIfExists('email_break_glass_accesses');
        Schema::dropIfExists('email_mailbox_delegations');
    }
};
