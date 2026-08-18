<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('imap_uid_validity')->default(0)->after('imap_uid');
        });

        Schema::create('email_folder_uid_namespaces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id');
            $table->foreignId('email_folder_id');
            $table->unsignedInteger('generation');
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->unsignedBigInteger('uid_next_at_establishment')->nullable();
            $table->unsignedBigInteger('live_start_uid')->nullable();
            $table->string('status', 32)->index();
            $table->string('provenance_code', 80);
            $table->foreignId('established_by')->nullable();
            $table->dateTime('established_at');
            $table->dateTime('superseded_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('account_id', 'em_uid_ns_acct_fk')
                ->references('id')->on('email_accounts')->cascadeOnDelete();
            $table->foreign('email_folder_id', 'em_uid_ns_folder_fk')
                ->references('id')->on('email_folders')->cascadeOnDelete();
            $table->foreign('established_by', 'em_uid_ns_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->unique(['email_folder_id', 'generation'], 'em_uid_ns_folder_gen_uq');
            $table->unique(['email_folder_id', 'uid_validity'], 'em_uid_ns_folder_uidv_uq');
            $table->index(['account_id', 'status'], 'em_uid_ns_acct_status_ix');
        });

        Schema::table('email_folders', function (Blueprint $table): void {
            $table->unsignedBigInteger('active_uid_namespace_id')->nullable()->after('live_start_uid');
            $table->foreign('active_uid_namespace_id', 'em_folder_active_uid_ns_fk')
                ->references('id')->on('email_folder_uid_namespaces')->nullOnDelete();
        });

        Schema::table('email_mailbox_placements', function (Blueprint $table): void {
            $table->unsignedBigInteger('uid_namespace_id')->nullable()->after('email_folder_id');
            $table->foreign('uid_namespace_id', 'em_place_uid_ns_fk')
                ->references('id')->on('email_folder_uid_namespaces')->nullOnDelete();
            $table->index(['uid_namespace_id', 'imap_uid'], 'em_place_uid_ns_uid_ix');
        });

        $this->backfillFolderNamespaces();
        $this->backfillMessageUidValidities();

        Schema::table('email_messages', function (Blueprint $table): void {
            $table->unique(
                ['account_id', 'mailbox', 'imap_uid_validity', 'imap_uid'],
                'em_msg_uid_ns_uq',
            );
            $table->dropUnique('uniq_account_mailbox_uid');
        });
    }

    public function down(): void
    {
        $hasNamespaceCollisions = DB::table('email_messages')
            ->select(['account_id', 'mailbox', 'imap_uid'])
            ->groupBy(['account_id', 'mailbox', 'imap_uid'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasNamespaceCollisions) {
            throw new \RuntimeException(
                'Cannot roll back Email UID namespaces after the same numeric UID exists in more than one namespace.',
            );
        }

        Schema::table('email_messages', function (Blueprint $table): void {
            $table->dropUnique('em_msg_uid_ns_uq');
            $table->unique(['account_id', 'mailbox', 'imap_uid'], 'uniq_account_mailbox_uid');
            $table->dropColumn('imap_uid_validity');
        });

        Schema::table('email_mailbox_placements', function (Blueprint $table): void {
            $table->dropForeign('em_place_uid_ns_fk');
            $table->dropIndex('em_place_uid_ns_uid_ix');
            $table->dropColumn('uid_namespace_id');
        });

        Schema::table('email_folders', function (Blueprint $table): void {
            $table->dropForeign('em_folder_active_uid_ns_fk');
            $table->dropColumn('active_uid_namespace_id');
        });

        Schema::dropIfExists('email_folder_uid_namespaces');
    }

    private function backfillFolderNamespaces(): void
    {
        DB::table('email_folders')
            ->orderBy('id')
            ->chunkById(200, function ($folders): void {
                foreach ($folders as $folder) {
                    $uidValidity = (int) ($folder->uid_validity ?? 0);
                    $namespaceId = DB::table('email_folder_uid_namespaces')->insertGetId([
                        'account_id' => $folder->account_id,
                        'email_folder_id' => $folder->id,
                        'generation' => 1,
                        'uid_validity' => $uidValidity > 0 ? $uidValidity : null,
                        'uid_next_at_establishment' => $folder->uid_next,
                        'live_start_uid' => $folder->live_start_uid,
                        'status' => $uidValidity > 0 ? 'active' : 'legacy_unknown',
                        'provenance_code' => $uidValidity > 0
                            ? 'migration_folder_baseline'
                            : 'migration_uidvalidity_unknown',
                        'established_at' => $folder->last_synced_at ?? $folder->created_at ?? now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($uidValidity <= 0) {
                        continue;
                    }

                    DB::table('email_folders')
                        ->where('id', $folder->id)
                        ->update(['active_uid_namespace_id' => $namespaceId]);

                    // Only an exact, positive legacy match is actionable. Old or
                    // zero-valued placement evidence deliberately remains unlinked.
                    DB::table('email_mailbox_placements')
                        ->where('account_id', $folder->account_id)
                        ->where('email_folder_id', $folder->id)
                        ->where('imap_uid_validity', $uidValidity)
                        ->update(['uid_namespace_id' => $namespaceId]);
                }
            });
    }

    private function backfillMessageUidValidities(): void
    {
        DB::table('email_messages')
            ->select(['id', 'account_id', 'mailbox', 'imap_uid'])
            ->orderBy('id')
            ->chunkById(200, function ($messages): void {
                foreach ($messages as $message) {
                    $validities = DB::table('email_mailbox_placements')
                        ->where('email_message_id', $message->id)
                        ->where('account_id', $message->account_id)
                        ->where('folder_path', $message->mailbox)
                        ->where('imap_uid', $message->imap_uid)
                        ->where('imap_uid_validity', '>', 0)
                        ->distinct()
                        ->pluck('imap_uid_validity');

                    if ($validities->count() !== 1) {
                        // Zero or ambiguous evidence remains explicitly unknown.
                        continue;
                    }

                    DB::table('email_messages')
                        ->where('id', $message->id)
                        ->update(['imap_uid_validity' => (int) $validities->first()]);
                }
            });
    }
};
