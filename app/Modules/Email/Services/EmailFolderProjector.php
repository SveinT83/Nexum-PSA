<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\DTOs\EmailPlacementCreateResult;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailLiveProjectionChange;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EmailFolderProjector
{
    public function __construct(
        private readonly EmailConversationProjector $conversations,
        private readonly EmailLiveInvalidator $invalidator,
    ) {}

    public function available(): bool
    {
        return Schema::hasTable('email_folders')
            && Schema::hasTable('email_mailbox_placements');
    }

    public function upsertFolder(EmailAccount $account, array $folderData): ?EmailFolder
    {
        if (! $this->available()) {
            return null;
        }

        $path = (string) ($folderData['path'] ?? 'INBOX');
        // Re-infer every discovery so legacy rows whose parent name leaked a
        // special role into descendants repair themselves without a data
        // migration. Provider SPECIAL-USE and the exact folder leaf remain
        // authoritative; a caller-supplied cached role does not.
        $role = EmailFolder::inferRole(
            $path,
            $folderData['special_use'] ?? null,
            $folderData['delimiter'] ?? null,
        );

        $folder = EmailFolder::query()->firstOrNew([
            'account_id' => $account->id,
            'path' => $path,
        ]);

        $incomingUidValidity = max(0, (int) ($folderData['uid_validity'] ?? 0));
        $activeUidValidity = $folder->exists
            ? $this->activeUidValidity($folder)
            : 0;
        $storedUidValidity = $activeUidValidity > 0
            ? $activeUidValidity
            : ($folder->exists && $folder->live_start_uid !== null && (int) $folder->uid_validity > 0
                ? (int) $folder->uid_validity
                : $incomingUidValidity);

        $folder->fill([
            'provider' => $folderData['provider'] ?? 'imap',
            'name' => $folderData['name'] ?? basename(str_replace('\\', '/', $path)) ?: $path,
            'delimiter' => $folderData['delimiter'] ?? null,
            'parent_path' => $folderData['parent_path'] ?? null,
            'remote_id' => $folderData['remote_id'] ?? null,
            'special_use' => $folderData['special_use'] ?? null,
            'role' => $role,
            'is_selectable' => (bool) ($folderData['is_selectable'] ?? true),
            'sync_enabled' => (bool) ($folderData['sync_enabled'] ?? true),
            'uid_validity' => $storedUidValidity,
            'uid_next' => isset($folderData['uid_next']) ? max(0, (int) $folderData['uid_next']) : null,
            'highest_modseq' => isset($folderData['highest_modseq']) ? max(0, (int) $folderData['highest_modseq']) : null,
            'exists_count' => isset($folderData['exists_count']) ? max(0, (int) $folderData['exists_count']) : null,
            'unseen_count' => isset($folderData['unseen_count']) ? max(0, (int) $folderData['unseen_count']) : null,
            'sync_status' => $activeUidValidity > 0
                && $incomingUidValidity > 0
                && $incomingUidValidity !== $activeUidValidity
                    ? EmailFolder::SYNC_ERROR
                    : ($folderData['sync_status'] ?? EmailFolder::SYNC_SYNCED),
            'last_discovered_at' => $folderData['last_discovered_at'] ?? now(),
            'last_synced_at' => $folderData['last_synced_at'] ?? $folder->last_synced_at,
            'sync_error_code' => $activeUidValidity > 0
                && $incomingUidValidity > 0
                && $incomingUidValidity !== $activeUidValidity
                    ? 'IMAP_UIDVALIDITY_CHANGED'
                    : ($folderData['sync_error_code'] ?? null),
            'sync_error_message' => $activeUidValidity > 0
                && $incomingUidValidity > 0
                && $incomingUidValidity !== $activeUidValidity
                    ? 'The provider folder UIDVALIDITY changed and requires an explicit cursor re-baseline.'
                    : ($folderData['sync_error_message'] ?? null),
        ]);

        if (array_key_exists('live_start_uid', $folderData)) {
            $folder->live_start_uid = max(0, (int) $folderData['live_start_uid']);
        }

        $folder->save();

        $this->ensureActiveUidNamespace($account, $folder);

        return $folder->refresh();
    }

    public function ensureFolderForMessage(EmailAccount $account, string $path, ?int $uidValidity = null): ?EmailFolder
    {
        if (! $this->available()) {
            return null;
        }

        $existing = EmailFolder::query()
            ->where('account_id', $account->id)
            ->where('path', $path)
            ->first();

        if ($existing) {
            if ($uidValidity && (int) $existing->uid_validity === 0) {
                $existing->forceFill(['uid_validity' => $uidValidity])->save();
            }

            $this->ensureActiveUidNamespace($account, $existing);

            return $existing->refresh();
        }

        return $this->upsertFolder($account, [
            'path' => $path,
            'uid_validity' => $uidValidity ?? 0,
            'sync_status' => EmailFolder::SYNC_SHADOW,
            'last_synced_at' => now(),
        ]);
    }

    public function upsertPlacement(EmailMessage $message, EmailFolder $folder, array $payload): ?EmailMailboxPlacement
    {
        if (! $this->available()) {
            return null;
        }

        $projection = $this->placementProjection($message, $folder, $payload);
        if ($projection === null) {
            return null;
        }

        $liveOperationId = (string) Str::uuid();

        return DB::transaction(function () use ($liveOperationId, $projection): EmailMailboxPlacement {
            $placement = EmailMailboxPlacement::query()->updateOrCreate(
                $projection['identity'],
                $projection['attributes'],
            );

            $this->conversations->assignPlacement($placement);
            $placement->refresh();

            $this->invalidator->record([
                'account' => [
                    $placement->account_id => [EmailLiveProjectionChange::TYPE_MAIL_PROJECTION],
                ],
                'conversations' => $placement->email_conversation_id ? [$placement->email_conversation_id] : [],
                'placements' => [$placement->id],
                'idempotency_key' => "placement-upsert:{$liveOperationId}",
            ]);

            return $placement->refresh();
        });
    }

    /**
     * Project one ordinary provider observation and freeze its move evidence.
     *
     * The observed version, strong identity (or explicit weak null), and
     * timestamp belong to the exact placement insert that projects provider
     * flags. Ordinary fetch payloads carry no dispatch-time placement version,
     * so an existing row is a duplicate/race and remains wholly untouched.
     * Reconciliation uses its own create-only seam as well.
     *
     * @param  array<string, mixed>  $payload
     */
    public function upsertProviderObservedPlacement(
        EmailMessage $message,
        EmailFolder $folder,
        array $payload,
        ?string $identityHash,
    ): ?EmailMailboxPlacement {
        if (! $this->available()) {
            return null;
        }

        $projection = $this->placementProjection($message, $folder, $payload);
        if ($projection === null) {
            return null;
        }

        $syncVersion = max(1, (int) ($projection['attributes']['sync_version'] ?? 1));
        $projection['attributes']['sync_version'] = $syncVersion;
        $projection['attributes']['last_provider_observed_sync_version'] = $syncVersion;
        $projection['attributes']['last_provider_observed_identity_hash'] = is_string($identityHash)
            && preg_match('/^[0-9a-f]{64}$/D', $identityHash) === 1
                ? $identityHash
                : null;
        $projection['attributes']['last_provider_observed_at'] = now();

        $liveOperationId = (string) Str::uuid();

        return DB::transaction(function () use ($liveOperationId, $projection): EmailMailboxPlacement {
            $placement = EmailMailboxPlacement::query()->firstOrCreate(
                $projection['identity'],
                $projection['attributes'],
            );

            if (! $placement->wasRecentlyCreated) {
                if (! $placement->email_conversation_id) {
                    // The placement insert is durable before conversation
                    // assignment. A hard loss in that narrow DB-only gap is
                    // resumed without applying any stale provider projection.
                    $this->conversations->assignPlacement($placement);
                }

                return $placement->refresh();
            }

            $this->conversations->assignPlacement($placement);
            $placement->refresh();

            $this->invalidator->record([
                'account' => [
                    $placement->account_id => [EmailLiveProjectionChange::TYPE_MAIL_PROJECTION],
                ],
                'conversations' => $placement->email_conversation_id ? [$placement->email_conversation_id] : [],
                'placements' => [$placement->id],
                'idempotency_key' => "provider-placement:{$liveOperationId}",
            ]);

            return $placement->refresh();
        });
    }

    /**
     * Create a reconciliation placement without ever applying incoming
     * provider/default attributes to an existing exact UID occurrence.
     *
     * An exact pending marker is durable crash evidence. Such a row may have
     * its conversation projection resumed, while every unrelated pre-existing
     * placement is returned byte-for-byte untouched.
     */
    public function createPlacementIfMissing(
        EmailMessage $message,
        EmailFolder $folder,
        array $payload,
    ): ?EmailPlacementCreateResult {
        if (! $this->available()) {
            return null;
        }

        $projection = $this->placementProjection($message, $folder, $payload);
        if ($projection === null) {
            return null;
        }
        if (($projection['attributes']['local_state'] ?? null) !== EmailMailboxPlacement::LOCAL_HIDDEN
            || ($projection['attributes']['sync_status'] ?? null) !== EmailMailboxPlacement::SYNC_PENDING
            || blank($projection['attributes']['sync_error_code'] ?? null)) {
            throw new \LogicException(
                'A create-only reconciliation placement requires an exact pending marker.',
            );
        }

        $placement = EmailMailboxPlacement::query()->firstOrCreate(
            $projection['identity'],
            $projection['attributes'],
        );
        $created = $placement->wasRecentlyCreated;
        $resumable = ! $created
            && $this->matchesPendingProjection($placement, $projection['attributes']);
        $disposition = $created
            ? EmailPlacementCreateResult::CREATED_PENDING
            : ($resumable
                ? EmailPlacementCreateResult::RESUMED_PENDING
                : EmailPlacementCreateResult::PREEXISTING);

        if (($created || $resumable) && ! $placement->email_conversation_id) {
            // A worker may die after the placement insert or conversation
            // pointer commit. Pending assignment deliberately does not update
            // any visible conversation aggregate or preview.
            $this->conversations->assignPendingPlacement($placement);
        }

        return new EmailPlacementCreateResult($placement->refresh(), $disposition);
    }

    /**
     * @return array{
     *     identity: array{account_id:int,email_folder_id:int,imap_uid_validity:int,imap_uid:int},
     *     attributes: array<string, mixed>
     * }|null
     */
    private function placementProjection(
        EmailMessage $message,
        EmailFolder $folder,
        array $payload,
    ): ?array {
        $uidValidity = max(0, (int) ($payload['uid_validity'] ?? $payload['imap_uid_validity'] ?? $folder->uid_validity ?? 0));
        $uid = max(0, (int) ($payload['imap_uid'] ?? $message->imap_uid));

        if ($uid <= 0) {
            return null;
        }

        $attributes = [
            'email_message_id' => $message->id,
            'provider' => $payload['provider'] ?? 'imap',
            'folder_path' => $folder->path,
            'remote_message_id' => $payload['remote_message_id'] ?? $message->message_id,
            'remote_modseq' => isset($payload['remote_modseq']) ? max(0, (int) $payload['remote_modseq']) : null,
            'provider_seen' => (bool) ($payload['provider_seen'] ?? false),
            'provider_answered' => (bool) ($payload['provider_answered'] ?? false),
            'provider_flagged' => (bool) ($payload['provider_flagged'] ?? false),
            'provider_deleted' => (bool) ($payload['provider_deleted'] ?? false),
            'provider_draft' => (bool) ($payload['provider_draft'] ?? false)
                || $folder->role === EmailFolder::ROLE_DRAFTS,
            'flags_json' => $payload['flags'] ?? $payload['flags_json'] ?? [],
            'labels_json' => $payload['labels'] ?? $payload['labels_json'] ?? [],
            'local_state' => $message->trashed()
                ? EmailMailboxPlacement::LOCAL_HIDDEN
                : ($payload['local_state'] ?? EmailMailboxPlacement::LOCAL_ACTIVE),
            'sync_status' => $payload['sync_status'] ?? EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => (int) ($payload['sync_version'] ?? 1),
            'last_reconciled_at' => $payload['last_reconciled_at'] ?? now(),
            'provider_missing_at' => $payload['provider_missing_at'] ?? null,
            'sync_error_code' => $payload['sync_error_code'] ?? null,
            'sync_error_message' => $payload['sync_error_message'] ?? null,
        ];

        if (array_key_exists('email_conversation_id', $payload)
            && Schema::hasColumn('email_mailbox_placements', 'email_conversation_id')) {
            $attributes['email_conversation_id'] = $payload['email_conversation_id'];
        }

        if (Schema::hasColumn('email_mailbox_placements', 'uid_namespace_id')) {
            $namespace = EmailFolderUidNamespace::query()
                ->whereKey($folder->active_uid_namespace_id)
                ->where('account_id', $message->account_id)
                ->where('email_folder_id', $folder->id)
                ->where('uid_validity', $uidValidity)
                ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
                ->first();
            $attributes['uid_namespace_id'] = $namespace?->id;
        }

        return [
            'identity' => [
                'account_id' => (int) $message->account_id,
                'email_folder_id' => (int) $folder->id,
                'imap_uid_validity' => $uidValidity,
                'imap_uid' => $uid,
            ],
            'attributes' => $attributes,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function matchesPendingProjection(
        EmailMailboxPlacement $placement,
        array $attributes,
    ): bool {
        $expectedCode = (string) ($attributes['sync_error_code'] ?? '');

        return $expectedCode !== ''
            && $placement->local_state === EmailMailboxPlacement::LOCAL_HIDDEN
            && $placement->sync_status === EmailMailboxPlacement::SYNC_PENDING
            && hash_equals($expectedCode, (string) $placement->sync_error_code);
    }

    private function activeUidValidity(EmailFolder $folder): int
    {
        if (! Schema::hasTable('email_folder_uid_namespaces')
            || ! Schema::hasColumn('email_folders', 'active_uid_namespace_id')
            || ! $folder->active_uid_namespace_id) {
            return 0;
        }

        return (int) EmailFolderUidNamespace::query()
            ->whereKey($folder->active_uid_namespace_id)
            ->where('email_folder_id', $folder->id)
            ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
            ->value('uid_validity');
    }

    /**
     * Establish the first positive namespace for a newly discovered folder.
     * A differing current namespace is never replaced here; only the explicit
     * re-baseline action may supersede proven UID identity.
     */
    private function ensureActiveUidNamespace(EmailAccount $account, EmailFolder $folder): void
    {
        if (! Schema::hasTable('email_folder_uid_namespaces')
            || ! Schema::hasColumn('email_folders', 'active_uid_namespace_id')
            || (int) $folder->uid_validity <= 0
            || $folder->active_uid_namespace_id) {
            return;
        }

        try {
            $namespace = EmailFolderUidNamespace::query()->firstOrCreate(
                [
                    'email_folder_id' => $folder->id,
                    'uid_validity' => (int) $folder->uid_validity,
                ],
                [
                    'account_id' => $account->id,
                    'generation' => max(1, (int) $folder->uidNamespaces()->max('generation') + 1),
                    'uid_next_at_establishment' => $folder->uid_next,
                    'live_start_uid' => $folder->live_start_uid,
                    'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
                    'provenance_code' => 'folder_discovery_baseline',
                    'established_at' => now(),
                ],
            );
        } catch (QueryException) {
            // Another worker may have established the same first namespace.
            $namespace = EmailFolderUidNamespace::query()
                ->where('email_folder_id', $folder->id)
                ->where('uid_validity', (int) $folder->uid_validity)
                ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
                ->first();
        }

        if ($namespace?->status !== EmailFolderUidNamespace::STATUS_ACTIVE) {
            return;
        }

        EmailFolder::query()
            ->whereKey($folder->id)
            ->whereNull('active_uid_namespace_id')
            ->update(['active_uid_namespace_id' => $namespace->id]);
    }
}
