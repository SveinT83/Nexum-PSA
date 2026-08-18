<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')
                ->constrained('email_accounts')
                ->cascadeOnDelete();
            $table->string('provider', 30)->default('imap')->index();
            $table->string('path', 512);
            $table->string('name', 255);
            $table->string('delimiter', 10)->nullable();
            $table->string('parent_path', 512)->nullable();
            $table->string('remote_id', 1024)->nullable();
            $table->string('special_use', 80)->nullable()->index();
            $table->string('role', 30)->default('custom')->index();
            $table->boolean('is_selectable')->default(true)->index();
            $table->boolean('sync_enabled')->default(true)->index();
            $table->unsignedBigInteger('uid_validity')->default(0);
            $table->unsignedBigInteger('uid_next')->nullable();
            $table->unsignedBigInteger('live_start_uid')->nullable();
            $table->unsignedBigInteger('highest_modseq')->nullable();
            $table->unsignedInteger('exists_count')->nullable();
            $table->unsignedInteger('unseen_count')->nullable();
            $table->string('sync_status', 40)->default('shadow')->index();
            $table->timestamp('last_discovered_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_error_code', 80)->nullable();
            $table->text('sync_error_message')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'path'], 'email_folders_account_path_unique');
            $table->index(['account_id', 'role'], 'email_folders_account_role_index');
            $table->index(['account_id', 'sync_enabled', 'is_selectable'], 'email_folders_sync_index');
        });

        Schema::create('email_mailbox_placements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_message_id')
                ->constrained('email_messages')
                ->cascadeOnDelete();
            $table->foreignId('account_id')
                ->constrained('email_accounts')
                ->cascadeOnDelete();
            $table->foreignId('email_folder_id')
                ->constrained('email_folders')
                ->cascadeOnDelete();
            $table->string('provider', 30)->default('imap')->index();
            $table->string('folder_path', 512);
            $table->string('remote_message_id', 1024)->nullable();
            $table->unsignedBigInteger('imap_uid_validity')->default(0);
            $table->unsignedBigInteger('imap_uid');
            $table->unsignedBigInteger('remote_modseq')->nullable();
            $table->boolean('provider_seen')->default(false)->index();
            $table->boolean('provider_answered')->default(false)->index();
            $table->boolean('provider_flagged')->default(false)->index();
            $table->boolean('provider_deleted')->default(false)->index();
            $table->boolean('provider_draft')->default(false)->index();
            $table->json('flags_json')->nullable();
            $table->json('labels_json')->nullable();
            $table->string('local_state', 40)->default('active')->index();
            $table->string('sync_status', 40)->default('synced')->index();
            $table->unsignedInteger('sync_version')->default(1);
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('provider_missing_at')->nullable();
            $table->string('sync_error_code', 80)->nullable();
            $table->text('sync_error_message')->nullable();
            $table->timestamps();

            $table->unique(
                ['account_id', 'email_folder_id', 'imap_uid_validity', 'imap_uid'],
                'email_placements_imap_unique',
            );
            $table->index(['email_message_id', 'local_state'], 'email_placements_message_state_index');
            $table->index(['account_id', 'folder_path'], 'email_placements_account_folder_index');
            $table->index(['email_folder_id', 'sync_status'], 'email_placements_folder_sync_index');
        });

        Schema::create('email_remote_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')
                ->constrained('email_accounts')
                ->cascadeOnDelete();
            $table->foreignId('email_folder_id')
                ->nullable()
                ->constrained('email_folders')
                ->nullOnDelete();
            $table->foreignId('email_mailbox_placement_id')
                ->nullable()
                ->constrained('email_mailbox_placements')
                ->nullOnDelete();
            $table->foreignId('requested_by')
                ->nullable()
                ->constrained('user_management')
                ->nullOnDelete();
            $table->string('provider', 30)->default('imap')->index();
            $table->string('operation_type', 60)->index();
            $table->string('status', 40)->default('pending')->index();
            $table->string('idempotency_key', 160)->unique();
            $table->string('source_folder_path', 512)->nullable();
            $table->string('target_folder_path', 512)->nullable();
            $table->json('request_json')->nullable();
            $table->json('provider_response_json')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status'], 'email_remote_ops_account_status_index');
            $table->index(['email_mailbox_placement_id', 'operation_type'], 'email_remote_ops_placement_type_index');
        });

        $this->backfillFolders();
        $this->backfillPlacements();
    }

    public function down(): void
    {
        Schema::dropIfExists('email_remote_operations');
        Schema::dropIfExists('email_mailbox_placements');
        Schema::dropIfExists('email_folders');
    }

    private function backfillFolders(): void
    {
        $now = now();
        $folders = [];

        foreach (DB::table('email_accounts')->get(['id', 'imap_uid_validity', 'imap_live_start_uid']) as $account) {
            $folders[] = [
                'account_id' => $account->id,
                'path' => 'INBOX',
                'name' => 'INBOX',
                'role' => 'inbox',
                'uid_validity' => (int) ($account->imap_uid_validity ?? 0),
                'live_start_uid' => isset($account->imap_live_start_uid) ? (int) $account->imap_live_start_uid : null,
            ];
        }

        DB::table('email_messages')
            ->select('account_id', 'mailbox')
            ->whereNotNull('mailbox')
            ->distinct()
            ->orderBy('account_id')
            ->orderBy('mailbox')
            ->get()
            ->each(function ($messageFolder) use (&$folders): void {
                $path = (string) $messageFolder->mailbox;
                $folders[] = [
                    'account_id' => $messageFolder->account_id,
                    'path' => $path,
                    'name' => basename(str_replace('\\', '/', $path)) ?: $path,
                    'role' => $this->inferFolderRole($path),
                    'uid_validity' => 0,
                    'live_start_uid' => null,
                ];
            });

        collect($folders)
            ->unique(fn (array $folder): string => $folder['account_id'].'|'.$folder['path'])
            ->values()
            ->each(function (array $folder) use ($now): void {
                DB::table('email_folders')->updateOrInsert(
                    [
                        'account_id' => $folder['account_id'],
                        'path' => $folder['path'],
                    ],
                    [
                        'provider' => 'imap',
                        'name' => $folder['name'],
                        'role' => $folder['role'],
                        'is_selectable' => true,
                        'sync_enabled' => true,
                        'uid_validity' => $folder['uid_validity'],
                        'live_start_uid' => $folder['live_start_uid'] ?? $this->existingFolderHighWaterUid(
                            (int) $folder['account_id'],
                            (string) $folder['path'],
                        ),
                        'sync_status' => 'shadow',
                        'last_discovered_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            });
    }

    private function backfillPlacements(): void
    {
        $folderIds = DB::table('email_folders')
            ->get(['id', 'account_id', 'path', 'uid_validity'])
            ->keyBy(fn ($folder): string => $folder->account_id.'|'.$folder->path);

        DB::table('email_messages')
            ->orderBy('id')
            ->chunkById(500, function ($messages) use ($folderIds): void {
                $now = now();
                $rows = [];

                foreach ($messages as $message) {
                    $folder = $folderIds->get($message->account_id.'|'.$message->mailbox);

                    if (! $folder) {
                        continue;
                    }

                    $rows[] = [
                        'email_message_id' => $message->id,
                        'account_id' => $message->account_id,
                        'email_folder_id' => $folder->id,
                        'provider' => 'imap',
                        'folder_path' => $message->mailbox,
                        'imap_uid_validity' => (int) ($folder->uid_validity ?? 0),
                        'imap_uid' => (int) $message->imap_uid,
                        'local_state' => $message->deleted_at ? 'hidden' : 'active',
                        'sync_status' => 'shadow',
                        'last_reconciled_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('email_mailbox_placements')->insertOrIgnore($rows);
                }
            });
    }

    private function inferFolderRole(string $path): string
    {
        $normalized = str_replace([' ', '_'], '-', mb_strtolower($path));

        return match (true) {
            $normalized === 'inbox' => 'inbox',
            str_contains($normalized, 'sent') => 'sent',
            str_contains($normalized, 'draft') => 'drafts',
            str_contains($normalized, 'trash') || str_contains($normalized, 'deleted') => 'trash',
            str_contains($normalized, 'archive') => 'archive',
            str_contains($normalized, 'junk') || str_contains($normalized, 'spam') => 'junk',
            default => 'custom',
        };
    }

    private function existingFolderHighWaterUid(int $accountId, string $path): ?int
    {
        $uid = DB::table('email_messages')
            ->where('account_id', $accountId)
            ->where('mailbox', $path)
            ->max('imap_uid');

        return $uid === null ? null : (int) $uid;
    }
};
