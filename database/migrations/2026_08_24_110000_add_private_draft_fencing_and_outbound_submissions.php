<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_composer_drafts')) {
            Schema::table('email_composer_drafts', function (Blueprint $table): void {
                // Nullable is deliberate rolling-deploy compatibility for an
                // additive migration over the existing draft writers. This
                // migration backfills every current row; the deployed model
                // creating hooks guarantee all new application rows. Drain
                // old writers during cutover and verify no null remains.
                if (! Schema::hasColumn('email_composer_drafts', 'public_id')) {
                    $table->uuid('public_id')->nullable()->after('id');
                }
                if (! Schema::hasColumn('email_composer_drafts', 'scope')) {
                    $table->string('scope', 24)->default('private')->after('user_id');
                }
                if (! Schema::hasColumn('email_composer_drafts', 'generation_id')) {
                    $table->uuid('generation_id')->nullable()->after('scope');
                }
                if (! Schema::hasColumn('email_composer_drafts', 'version')) {
                    $table->unsignedBigInteger('version')->default(1)->after('generation_id');
                }
            });

            DB::table('email_composer_drafts')
                ->select(['id', 'public_id', 'generation_id'])
                ->orderBy('id')
                ->chunkById(100, function ($drafts): void {
                    foreach ($drafts as $draft) {
                        DB::table('email_composer_drafts')
                            ->where('id', $draft->id)
                            ->update([
                                'public_id' => filled($draft->public_id)
                                    ? $draft->public_id
                                    : (string) Str::uuid(),
                                'generation_id' => filled($draft->generation_id)
                                    ? $draft->generation_id
                                    : (string) Str::uuid(),
                            ]);
                    }
                });

            Schema::table('email_composer_drafts', function (Blueprint $table): void {
                $table->unique('public_id', 'email_composer_drafts_public_id_unique');
                $table->index(
                    ['user_id', 'scope', 'status', 'last_saved_at'],
                    'email_composer_drafts_private_list_index',
                );
            });
        }

        if (Schema::hasTable('email_composer_draft_attachments')) {
            Schema::table('email_composer_draft_attachments', function (Blueprint $table): void {
                // See the draft-column rolling-deploy note above. Current
                // attachment creation derives its generation from the owning
                // draft and always creates an opaque public UUID.
                if (! Schema::hasColumn('email_composer_draft_attachments', 'public_id')) {
                    $table->uuid('public_id')->nullable()->after('id');
                }
                if (! Schema::hasColumn('email_composer_draft_attachments', 'draft_generation_id')) {
                    $table->uuid('draft_generation_id')->nullable()->after('email_composer_draft_id');
                }
            });

            DB::table('email_composer_draft_attachments')
                ->leftJoin(
                    'email_composer_drafts',
                    'email_composer_drafts.id',
                    '=',
                    'email_composer_draft_attachments.email_composer_draft_id',
                )
                ->select([
                    'email_composer_draft_attachments.id',
                    'email_composer_draft_attachments.public_id',
                    'email_composer_draft_attachments.draft_generation_id',
                    'email_composer_drafts.generation_id',
                ])
                ->orderBy('email_composer_draft_attachments.id')
                ->chunkById(100, function ($attachments): void {
                    foreach ($attachments as $attachment) {
                        DB::table('email_composer_draft_attachments')
                            ->where('id', $attachment->id)
                            ->update([
                                'public_id' => filled($attachment->public_id)
                                    ? $attachment->public_id
                                    : (string) Str::uuid(),
                                'draft_generation_id' => filled($attachment->draft_generation_id)
                                    ? $attachment->draft_generation_id
                                    : $attachment->generation_id,
                            ]);
                    }
                }, 'email_composer_draft_attachments.id', 'id');

            Schema::table('email_composer_draft_attachments', function (Blueprint $table): void {
                $table->unique('public_id', 'email_draft_attachments_public_id_unique');
                $table->index(
                    ['email_composer_draft_id', 'draft_generation_id'],
                    'email_draft_attachments_generation_index',
                );
            });
        }

        if (! Schema::hasTable('email_outbound_submissions')) {
            Schema::create('email_outbound_submissions', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique('email_outbound_submissions_public_id_unique');
                $table->foreignId('email_account_id')
                    ->nullable()
                    ->constrained('email_accounts', indexName: 'email_outbound_submission_account_fk')
                    ->nullOnDelete();
                $table->foreignId('actor_id')
                    ->nullable()
                    ->constrained('user_management', indexName: 'email_outbound_submission_actor_fk')
                    ->nullOnDelete();
                $table->foreignId('email_composer_draft_id')
                    ->nullable()
                    ->constrained('email_composer_drafts', indexName: 'email_outbound_submission_draft_fk')
                    ->nullOnDelete();
                $table->foreignId('source_email_message_id')
                    ->nullable()
                    ->constrained('email_messages', indexName: 'email_outbound_submission_message_fk')
                    ->nullOnDelete();
                $table->foreignId('source_email_mailbox_placement_id')
                    ->nullable()
                    ->constrained('email_mailbox_placements', indexName: 'email_outbound_submission_place_fk')
                    ->nullOnDelete();
                $table->foreignId('email_log_id')
                    ->nullable()
                    ->constrained('email_logs', indexName: 'email_outbound_submission_log_fk')
                    ->nullOnDelete();
                $table->foreignId('email_sent_reconciliation_id')
                    ->nullable()
                    ->constrained('email_sent_reconciliations', indexName: 'email_outbound_submission_sent_fk')
                    ->nullOnDelete();
                $table->string('mode', 24);
                $table->string('caller_channel', 32);
                $table->string('client_idempotency_key', 120);
                $table->char('request_fingerprint', 64);
                $table->uuid('draft_generation_id');
                $table->unsignedBigInteger('draft_version');
                $table->unsignedBigInteger('provider_binding_version')->default(0);
                $table->foreignId('email_signature_id')
                    ->nullable()
                    ->constrained('email_signatures', indexName: 'email_outbound_submission_signature_fk')
                    ->nullOnDelete();
                $table->string('signature_source', 64)->nullable();
                $table->char('attachment_manifest_hash', 64);
                $table->string('reserved_message_id', 255)->nullable();
                $table->string('status', 32)->default('reserved');
                $table->string('result_code', 96)->nullable();
                $table->string('reason_code', 96)->nullable();
                $table->timestamp('provider_write_started_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('reconciled_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['actor_id', 'email_account_id', 'caller_channel', 'client_idempotency_key'],
                    'email_outbound_submission_client_key_unique',
                );
                $table->unique(
                    ['email_composer_draft_id', 'draft_generation_id', 'draft_version'],
                    'email_outbound_submission_draft_version_unique',
                );
                $table->index(
                    ['email_composer_draft_id', 'draft_generation_id', 'status'],
                    'email_outbound_submission_generation_status_index',
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('email_outbound_submissions')
            && DB::table('email_outbound_submissions')->exists()) {
            throw new RuntimeException(
                'Email outbound submission evidence must be preserved or carried forward before rollback.',
            );
        }

        Schema::dropIfExists('email_outbound_submissions');

        if (Schema::hasTable('email_composer_draft_attachments')) {
            Schema::table('email_composer_draft_attachments', function (Blueprint $table): void {
                $table->dropIndex('email_draft_attachments_generation_index');
                $table->dropUnique('email_draft_attachments_public_id_unique');
                $table->dropColumn(['public_id', 'draft_generation_id']);
            });
        }

        if (Schema::hasTable('email_composer_drafts')) {
            Schema::table('email_composer_drafts', function (Blueprint $table): void {
                $table->dropIndex('email_composer_drafts_private_list_index');
                $table->dropUnique('email_composer_drafts_public_id_unique');
                $table->dropColumn(['public_id', 'scope', 'generation_id', 'version']);
            });
        }
    }
};
