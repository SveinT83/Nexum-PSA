<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the durable half of Order 9 without enabling collaboration. Reading
     * and typing presence intentionally remains outside SQL in expiring cache.
     */
    public function up(): void
    {
        if (Schema::hasTable('email_composer_drafts')) {
            Schema::table('email_composer_drafts', function (Blueprint $table): void {
                if (! Schema::hasColumn('email_composer_drafts', 'email_conversation_id')) {
                    $table->foreignId('email_conversation_id')
                        ->nullable()
                        ->after('email_mailbox_placement_id')
                        ->constrained('email_conversations', indexName: 'email_draft_conversation_fk')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('email_composer_drafts', 'shared_scope_id')) {
                    $table->uuid('shared_scope_id')->nullable()->after('generation_id');
                }
                if (! Schema::hasColumn('email_composer_drafts', 'shared_by_id')) {
                    $table->foreignId('shared_by_id')
                        ->nullable()
                        ->after('shared_scope_id')
                        ->constrained('user_management', indexName: 'email_draft_shared_by_fk')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('email_composer_drafts', 'shared_at')) {
                    $table->timestamp('shared_at')->nullable()->after('shared_by_id');
                }
                if (! Schema::hasColumn('email_composer_drafts', 'sharing_revoked_at')) {
                    $table->timestamp('sharing_revoked_at')->nullable()->after('shared_at');
                }
                if (! Schema::hasColumn('email_composer_drafts', 'content_version')) {
                    $table->unsignedBigInteger('content_version')->default(1)->after('version');
                }
                if (! Schema::hasColumn('email_composer_drafts', 'source_context_schema')) {
                    $table->unsignedSmallInteger('source_context_schema')->default(1)->after('content_version');
                }
                if (! Schema::hasColumn('email_composer_drafts', 'source_context_fingerprint')) {
                    $table->char('source_context_fingerprint', 64)->nullable()->after('source_context_schema');
                }
                if (! Schema::hasColumn('email_composer_drafts', 'source_context_captured_at')) {
                    $table->timestamp('source_context_captured_at')->nullable()->after('source_context_fingerprint');
                }
                if (! Schema::hasColumn('email_composer_drafts', 'source_placement_sync_version')) {
                    $table->unsignedBigInteger('source_placement_sync_version')
                        ->nullable()
                        ->after('source_context_captured_at');
                }
                if (! Schema::hasColumn('email_composer_drafts', 'stale_reason_code')) {
                    $table->string('stale_reason_code', 96)->nullable()->after('source_placement_sync_version');
                }
                if (! Schema::hasColumn('email_composer_drafts', 'stale_at')) {
                    $table->timestamp('stale_at')->nullable()->after('stale_reason_code');
                }
                if (! Schema::hasColumn('email_composer_drafts', 'last_rebased_at')) {
                    $table->timestamp('last_rebased_at')->nullable()->after('stale_at');
                }
            });

            // Existing rows remain private. These compatibility values let the
            // new code inspect them without changing ownership or visibility.
            DB::table('email_composer_drafts')->update([
                'content_version' => DB::raw('CASE WHEN version > 0 THEN version ELSE 1 END'),
            ]);
            DB::table('email_composer_drafts')
                ->whereNull('email_conversation_id')
                ->whereNotNull('email_mailbox_placement_id')
                ->update([
                    'email_conversation_id' => DB::raw('(SELECT email_conversation_id FROM email_mailbox_placements WHERE email_mailbox_placements.id = email_composer_drafts.email_mailbox_placement_id)'),
                    'source_placement_sync_version' => DB::raw('(SELECT sync_version FROM email_mailbox_placements WHERE email_mailbox_placements.id = email_composer_drafts.email_mailbox_placement_id)'),
                ]);

            Schema::table('email_composer_drafts', function (Blueprint $table): void {
                $table->unique('shared_scope_id', 'email_drafts_shared_scope_unique');
                $table->index(
                    ['scope', 'email_account_id', 'email_conversation_id', 'status'],
                    'email_drafts_shared_context_index',
                );
            });
        }

        if (! Schema::hasTable('email_shared_draft_locks')) {
            Schema::create('email_shared_draft_locks', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique('email_shared_draft_locks_public_unique');
                $table->foreignId('email_composer_draft_id')
                    ->constrained('email_composer_drafts', indexName: 'email_shared_lock_draft_fk')
                    ->cascadeOnDelete();
                $table->uuid('draft_generation_id');
                $table->foreignId('email_account_id')
                    ->constrained('email_accounts', indexName: 'email_shared_lock_account_fk')
                    ->restrictOnDelete();
                $table->foreignId('email_conversation_id')
                    ->constrained('email_conversations', indexName: 'email_shared_lock_conversation_fk')
                    ->restrictOnDelete();
                $table->foreignId('source_email_mailbox_placement_id')
                    ->constrained('email_mailbox_placements', indexName: 'email_shared_lock_source_fk')
                    ->restrictOnDelete();
                $table->foreignId('holder_id')
                    ->nullable()
                    ->constrained('user_management', indexName: 'email_shared_lock_holder_fk')
                    ->nullOnDelete();
                $table->char('lease_token_hash', 64)->nullable();
                $table->unsignedBigInteger('fencing_token')->default(0);
                $table->unsignedBigInteger('content_version')->default(1);
                $table->timestamp('acquired_at')->nullable();
                $table->timestamp('renewed_at')->nullable();
                $table->timestamp('lease_expires_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->string('release_reason_code', 96)->nullable();
                $table->timestamps();

                $table->unique('email_composer_draft_id', 'email_shared_lock_one_per_draft');
                $table->index(
                    ['email_account_id', 'email_conversation_id', 'lease_expires_at'],
                    'email_shared_lock_context_expiry_index',
                );
                $table->index(['holder_id', 'lease_expires_at'], 'email_shared_lock_holder_expiry_index');
            });
        }

        if (! Schema::hasTable('email_shared_draft_events')) {
            Schema::create('email_shared_draft_events', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique('email_shared_draft_events_public_unique');
                $table->foreignId('email_composer_draft_id')
                    ->constrained('email_composer_drafts', indexName: 'email_shared_event_draft_fk')
                    ->cascadeOnDelete();
                $table->foreignId('email_shared_draft_lock_id')
                    ->nullable()
                    ->constrained('email_shared_draft_locks', indexName: 'email_shared_event_lock_fk')
                    ->nullOnDelete();
                $table->foreignId('actor_id')
                    ->nullable()
                    ->constrained('user_management', indexName: 'email_shared_event_actor_fk')
                    ->nullOnDelete();
                $table->string('event_type', 40);
                $table->unsignedBigInteger('fencing_token')->default(0);
                $table->unsignedBigInteger('content_version')->default(1);
                $table->string('safe_reason_code', 96)->nullable();
                $table->string('idempotency_key', 120);
                $table->timestamp('occurred_at');
                $table->timestamps();

                $table->unique(
                    ['email_composer_draft_id', 'idempotency_key'],
                    'email_shared_event_draft_idempotency_unique',
                );
                $table->index(
                    ['email_composer_draft_id', 'occurred_at'],
                    'email_shared_event_draft_time_index',
                );
            });
        }
    }

    public function down(): void
    {
        $hasEvidence = (Schema::hasTable('email_shared_draft_events')
                && DB::table('email_shared_draft_events')->exists())
            || (Schema::hasTable('email_shared_draft_locks')
                && DB::table('email_shared_draft_locks')->exists())
            || (Schema::hasTable('email_composer_drafts')
                && Schema::hasColumn('email_composer_drafts', 'scope')
                && DB::table('email_composer_drafts')->where('scope', 'shared')->exists());

        if ($hasEvidence) {
            throw new RuntimeException('Refusing to drop non-empty shared-draft coordination evidence.');
        }

        Schema::dropIfExists('email_shared_draft_events');
        Schema::dropIfExists('email_shared_draft_locks');

        if (Schema::hasTable('email_composer_drafts')
            && Schema::hasColumn('email_composer_drafts', 'shared_scope_id')) {
            Schema::table('email_composer_drafts', function (Blueprint $table): void {
                $table->dropUnique('email_drafts_shared_scope_unique');
                $table->dropIndex('email_drafts_shared_context_index');
                $table->dropForeign('email_draft_conversation_fk');
                $table->dropForeign('email_draft_shared_by_fk');
                $table->dropColumn([
                    'email_conversation_id',
                    'shared_scope_id',
                    'shared_by_id',
                    'shared_at',
                    'sharing_revoked_at',
                    'content_version',
                    'source_context_schema',
                    'source_context_fingerprint',
                    'source_context_captured_at',
                    'source_placement_sync_version',
                    'stale_reason_code',
                    'stale_at',
                    'last_rebased_at',
                ]);
            });
        }
    }
};
