<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Contracts\EmailProviderReconciliationMessageStore;
use App\Modules\Email\DTOs\EmailProviderReconciliationPeekedMessage;
use App\Modules\Email\DTOs\EmailProviderReconciliationStoredMessage;
use App\Modules\Email\Jobs\StoreInboundMessage;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Support\EmailAccountProviderLockContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Store one exact, already-read provider message through the inbound writer.
 *
 * The adapter never exposes an option for provider mutation and accepts only a
 * detached in-process PEEK envelope. Persistence exceptions are severed here
 * so database bindings containing message data cannot enter failed-job logs.
 */
final class EmailProviderReconciliationStore implements EmailProviderReconciliationMessageStore
{
    public const HISTORICAL_BASELINE_PENDING_CODE = 'reconciliation_historical_baseline_pending';

    public const STORE_PENDING_CODE = 'reconciliation_store_pending';

    public function __construct(
        private readonly EmailProviderMessageIdentity $identities,
        private readonly EmailCanonicalSelfMapper $canonicalSelfMapper,
        private readonly EmailRawMessageSnapshot $rawSnapshots,
    ) {}

    public function store(
        int $runId,
        int $itemId,
        int $claimAttempt,
        int $accountId,
        int $folderId,
        int $uidNamespaceId,
        int $uidValidity,
        int $uid,
        #[\SensitiveParameter] EmailProviderReconciliationPeekedMessage $peeked,
        bool $runInboundRules,
    ): EmailProviderReconciliationStoredMessage {
        if (! EmailAccountProviderLockContext::held($accountId)) {
            throw new EmailProviderReconciliationReadException(
                'reconciliation_store_provider_lock_missing',
            );
        }

        [$run, $folderRun, $folder] = $this->authorizeDurableScope(
            $runId,
            $itemId,
            $claimAttempt,
            $accountId,
            $folderId,
            $uidNamespaceId,
            $uidValidity,
            $uid,
        );
        $payload = $peeked->payload();
        $this->assertEnvelope(
            $payload,
            $peeked,
            $run,
            $folderRun,
            $folder,
            $uidValidity,
            $uid,
        );

        $payload['provider_reconciliation_run_id'] = $runId;
        $payload['provider_reconciliation_item_id'] = $itemId;
        // Reconciliation automation is durable work owned by the import item.
        // StoreInboundMessage must never dispatch it before this adapter has
        // verified every local artifact and accepted the exact placement.
        $payload['run_inbound_rules'] = false;
        $payload['allow_provider_mutation'] = false;
        $payload['run_provider_reconciliation'] = false;
        $deferHistoricalBaseline = $this->defersHistoricalReadBaseline(
            $folderRun,
            $folder,
            $uidNamespaceId,
            $uidValidity,
            $uid,
        );
        if ($deferHistoricalBaseline) {
            // The placement is born hidden. A post-store update would leave a
            // small but real window where history could enter Mail before all
            // current viewers have their read-for-me baseline.
            $payload['local_state'] = EmailMailboxPlacement::LOCAL_HIDDEN;
            $payload['sync_status'] = EmailMailboxPlacement::SYNC_PENDING;
            $payload['sync_error_code'] = self::HISTORICAL_BASELINE_PENDING_CODE;
            $payload['sync_error_message'] = null;
        } else {
            // Every reconciliation import remains invisible until the adapter
            // has attested raw bytes, attachment parity, and canonical state.
            $payload['local_state'] = EmailMailboxPlacement::LOCAL_HIDDEN;
            $payload['sync_status'] = EmailMailboxPlacement::SYNC_PENDING;
            $payload['sync_error_code'] = self::STORE_PENDING_CODE;
            $payload['sync_error_message'] = null;
        }

        try {
            $store = new StoreInboundMessage($payload);
            $store->withPreloadedProviderMessage($peeked);
            app()->call([$store, 'handle']);
            $placementResult = $store->preloadedPlacementResult();

            $message = EmailMessage::withTrashed()
                ->where('account_id', $accountId)
                ->where('mailbox', $folderRun->folder_path)
                ->where('imap_uid_validity', $uidValidity)
                ->where('imap_uid', $uid)
                ->first();
            $placement = EmailMailboxPlacement::query()
                ->where('account_id', $accountId)
                ->where('email_folder_id', $folderId)
                ->where('uid_namespace_id', $uidNamespaceId)
                ->where('imap_uid_validity', $uidValidity)
                ->where('imap_uid', $uid)
                ->first();
            if (! $message || ! $placement || ! $placementResult
                || (int) $placementResult->placement->id !== (int) $placement->id
                || (int) $placement->email_message_id !== (int) $message->id) {
                throw new EmailProviderReconciliationReadException(
                    'reconciliation_store_result_missing',
                );
            }
            if ($placementResult->reconciliationPending()) {
                $this->assertPendingPlacement(
                    $placement,
                    $deferHistoricalBaseline
                        ? self::HISTORICAL_BASELINE_PENDING_CODE
                        : self::STORE_PENDING_CODE,
                );
            }
            $this->assertStoredArtifacts(
                $message,
                (bool) ($payload['is_oversize'] ?? false),
                $peeked,
                $placementResult->reconciliationPending(),
            );
            // This atomic, idempotent canonical self-map + placement pointer is
            // the sole PREEXISTING mutation allowed by the Store boundary.
            // It cannot change content, placement state/version/provider facts,
            // conversation projection, Draft/Sent projection, or private files.
            $this->ensureCanonicalProjection($message, $placement);
            $placement->refresh();

            return new EmailProviderReconciliationStoredMessage(
                (int) $message->id,
                (int) $placement->id,
                $this->identities->forMessage($message),
                $placementResult->disposition,
                max(1, (int) $placement->sync_version),
            );
        } catch (EmailProviderReconciliationReadException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Do not retain the persistence exception as `previous`: SQL
            // bindings can contain subject, addresses, headers, and body.
            throw new EmailProviderReconciliationReadException('reconciliation_store_failed');
        }
    }

    private function ensureCanonicalProjection(
        EmailMessage $message,
        EmailMailboxPlacement $placement,
    ): void {
        $hasCanonicalMessages = Schema::hasTable('email_canonical_messages');
        $hasCanonicalSources = Schema::hasTable('email_canonical_message_sources');
        $hasPlacementPointer = Schema::hasColumn(
            'email_mailbox_placements',
            'canonical_email_message_id',
        );

        if (! $hasCanonicalMessages && ! $hasCanonicalSources && ! $hasPlacementPointer) {
            return;
        }
        if (! $hasCanonicalMessages || ! $hasCanonicalSources || ! $hasPlacementPointer) {
            throw new EmailProviderReconciliationReadException(
                'reconciliation_canonical_schema_incomplete',
            );
        }

        try {
            $mapping = $this->canonicalSelfMapper->map($message);
        } catch (Throwable) {
            throw new EmailProviderReconciliationReadException(
                'reconciliation_canonical_projection_failed',
            );
        }

        $canonicalId = (int) ($mapping?->canonical_email_message_id ?? 0);
        $mappingValid = $mapping
            && (int) $mapping->source_email_message_id === (int) $message->id
            && $canonicalId > 0
            && \App\Modules\Email\Models\EmailCanonicalMessage::query()
                ->whereKey($canonicalId)
                ->exists();
        $placementValid = EmailMailboxPlacement::query()
            ->whereKey($placement->id)
            ->where('email_message_id', $message->id)
            ->where('canonical_email_message_id', $canonicalId)
            ->exists();

        if (! $mappingValid || ! $placementValid) {
            throw new EmailProviderReconciliationReadException(
                'reconciliation_canonical_projection_incomplete',
            );
        }
    }

    private function assertStoredArtifacts(
        EmailMessage $message,
        bool $oversize,
        EmailProviderReconciliationPeekedMessage $peeked,
        bool $reconciliationPending,
    ): void {
        if ($message->trashed()) {
            throw new EmailProviderReconciliationReadException(
                'reconciliation_store_artifacts_incomplete',
            );
        }

        if (! $oversize) {
            $rawPath = str_replace('\\', '/', trim((string) $message->raw_path));
            $expectedRawPath = sprintf(
                'email/raw/v2/%d/%s/%d/%d.eml',
                (int) $message->account_id,
                hash('sha256', (string) $message->mailbox),
                max(0, (int) $message->imap_uid_validity),
                (int) $message->imap_uid,
            );
            try {
                $expectedRaw = $this->rawSnapshots->serialize($peeked->message());
            } catch (Throwable) {
                $expectedRaw = null;
            }
            $pathValid = $reconciliationPending
                ? hash_equals($expectedRawPath, $rawPath)
                : $rawPath !== ''
                    && str_starts_with($rawPath, 'email/raw/')
                    && ! str_contains('/'.$rawPath.'/', '/../');
            if (! $pathValid
                || $expectedRaw === null
                || ! $this->storedFileMatches(
                    $rawPath,
                    strlen($expectedRaw),
                    hash('sha256', $expectedRaw),
                    'sha256',
                )) {
                throw new EmailProviderReconciliationReadException(
                    'reconciliation_store_artifacts_incomplete',
                );
            }
        }

        $attachments = $message->attachments()->get([
            'id',
            'path',
            'disk',
            'size_bytes',
            'checksum_sha1',
        ]);
        if ($attachments->count() !== (int) $message->attachments_count
            || $attachments->contains(function ($attachment): bool {
                $path = str_replace('\\', '/', (string) $attachment->path);
                if ($path === ''
                    || ! str_starts_with($path, 'email/attachments/')
                    || str_contains('/'.$path.'/', '/../')
                    || (string) $attachment->disk !== 'local'
                    || ! preg_match('/^[a-f0-9]{40}$/', (string) $attachment->checksum_sha1)) {
                    return true;
                }

                return ! $this->storedFileMatches(
                    $path,
                    (int) $attachment->size_bytes,
                    (string) $attachment->checksum_sha1,
                    'sha1',
                );
            })) {
            throw new EmailProviderReconciliationReadException(
                'reconciliation_store_artifacts_incomplete',
            );
        }
    }

    private function storedFileMatches(
        string $path,
        int $expectedBytes,
        string $expectedDigest,
        string $algorithm,
    ): bool {
        $stream = null;

        try {
            $stream = Storage::disk('local')->readStream($path);
            if (! is_resource($stream)) {
                return false;
            }

            $digest = hash_init($algorithm);
            $readBytes = hash_update_stream($digest, $stream);

            return $readBytes === $expectedBytes
                && hash_equals($expectedDigest, hash_final($digest));
        } catch (Throwable) {
            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function defersHistoricalReadBaseline(
        EmailProviderReconciliationFolder $folderRun,
        EmailFolder $folder,
        int $uidNamespaceId,
        int $uidValidity,
        int $uid,
    ): bool {
        $newFolderHistory = $folderRun->discovery_state
                === EmailProviderReconciliationFolder::DISCOVERY_NEW_AFTER_BASELINE
            && $folderRun->import_policy
                === EmailProviderReconciliationFolder::IMPORT_NEW_FOLDER_NO_RULES;
        if ($newFolderHistory) {
            // The folder-run high-water is immutable discovery evidence. The
            // mutable folder projection is only a fail-closed parity check;
            // it can never widen which UIDs become historical.
            $frozenHighWater = (int) $folderRun->scan_through_uid;
            if ((int) $folder->live_start_uid !== $frozenHighWater) {
                throw new EmailProviderReconciliationReadException(
                    'reconciliation_new_folder_baseline_scope_mismatch',
                );
            }
            if ($uid > $frozenHighWater) {
                // A later live UID belongs to a separate IMPORT_LIVE cycle.
                // Accepting it under the no-rules historical policy would
                // make it visible without ordinary automation.
                throw new EmailProviderReconciliationReadException(
                    'reconciliation_new_folder_baseline_scope_mismatch',
                );
            }

            return true;
        }

        // A later cycle may rediscover an interrupted historical import under
        // the normal LIVE policy. The exact hidden marker is durable authority
        // to repair it; mutable folder policy alone can never create that
        // authority.
        return EmailMailboxPlacement::query()
            ->where('account_id', $folderRun->account_id)
            ->where('email_folder_id', $folder->id)
            ->where('uid_namespace_id', $uidNamespaceId)
            ->where('imap_uid_validity', $uidValidity)
            ->where('imap_uid', $uid)
            ->where('local_state', EmailMailboxPlacement::LOCAL_HIDDEN)
            ->where('sync_status', EmailMailboxPlacement::SYNC_PENDING)
            ->where('sync_error_code', self::HISTORICAL_BASELINE_PENDING_CODE)
            ->exists();
    }

    private function assertPendingPlacement(
        EmailMailboxPlacement $placement,
        string $expectedErrorCode,
    ): void {
        if ($placement->local_state !== EmailMailboxPlacement::LOCAL_HIDDEN
            || $placement->sync_status !== EmailMailboxPlacement::SYNC_PENDING
            || ! hash_equals($expectedErrorCode, (string) $placement->sync_error_code)) {
            throw new EmailProviderReconciliationReadException(
                $expectedErrorCode === self::HISTORICAL_BASELINE_PENDING_CODE
                    ? 'reconciliation_historical_baseline_placement_incomplete'
                    : 'reconciliation_store_pending_placement_incomplete',
            );
        }
    }

    /**
     * @return array{0: EmailProviderReconciliationRun, 1: EmailProviderReconciliationFolder, 2: EmailFolder}
     */
    private function authorizeDurableScope(
        int $runId,
        int $itemId,
        int $claimAttempt,
        int $accountId,
        int $folderId,
        int $uidNamespaceId,
        int $uidValidity,
        int $uid,
    ): array {
        if (min(
            $runId,
            $itemId,
            $claimAttempt,
            $accountId,
            $folderId,
            $uidNamespaceId,
            $uidValidity,
            $uid,
        ) < 1) {
            throw new EmailProviderReconciliationReadException('reconciliation_store_scope_invalid');
        }

        $folderRunId = (int) EmailProviderReconciliationItem::query()
            ->whereKey($itemId)
            ->where('email_provider_reconciliation_run_id', $runId)
            ->value('email_provider_reconciliation_folder_id');
        if ($folderRunId < 1) {
            throw new EmailProviderReconciliationReadException('reconciliation_store_claim_stale');
        }

        return DB::transaction(function () use (
            $accountId,
            $claimAttempt,
            $folderId,
            $folderRunId,
            $itemId,
            $runId,
            $uid,
            $uidNamespaceId,
            $uidValidity,
        ): array {
            // This short authorization transaction is the Store side of the
            // cancellation linearization. Private bytes are written only
            // after these locks have committed and never inside this scope.
            $run = EmailProviderReconciliationRun::query()
                ->whereKey($runId)
                ->where('account_id', $accountId)
                ->lockForUpdate()
                ->first();
            $folderRun = EmailProviderReconciliationFolder::query()
                ->whereKey($folderRunId)
                ->where('email_provider_reconciliation_run_id', $runId)
                ->where('account_id', $accountId)
                ->where('email_folder_id', $folderId)
                ->where('uid_namespace_id', $uidNamespaceId)
                ->where('expected_uid_validity', $uidValidity)
                ->lockForUpdate()
                ->first();
            $item = EmailProviderReconciliationItem::query()
                ->whereKey($itemId)
                ->where('email_provider_reconciliation_run_id', $runId)
                ->where('email_provider_reconciliation_folder_id', $folderRunId)
                ->where('uid_namespace_id', $uidNamespaceId)
                ->where('imap_uid', $uid)
                ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
                ->lockForUpdate()
                ->first();
            if (! $run || ! $folderRun || ! $item
                || $run->cancellation_requested_at !== null
                || (int) $run->active_slot !== 1
                || ! in_array($run->status, [
                    EmailProviderReconciliationRun::STATUS_RUNNING,
                    EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
                ], true)
                // A claimed PEEK body may enter Store only while provider
                // import work is still authoritative. Finalization and its
                // bounded summary phase are write barriers, even for a stale
                // worker that still holds the account provider lease.
                || ! in_array($run->phase, [
                    EmailProviderReconciliationRun::PHASE_SCAN,
                    EmailProviderReconciliationRun::PHASE_IMPORTS,
                ], true)
                || ! in_array($folderRun->status, [
                    EmailProviderReconciliationFolder::STATUS_SCANNING,
                    EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
                ], true)
                || $item->status !== EmailProviderReconciliationItem::STATUS_RUNNING
                || (int) $item->attempt_count !== $claimAttempt) {
                throw new EmailProviderReconciliationReadException(
                    'reconciliation_store_claim_stale',
                );
            }

            $folder = EmailFolder::query()
                ->whereKey($folderId)
                ->where('account_id', $accountId)
                ->where('active_uid_namespace_id', $uidNamespaceId)
                ->lockForUpdate()
                ->first();
            $namespace = EmailFolderUidNamespace::query()
                ->whereKey($uidNamespaceId)
                ->where('account_id', $accountId)
                ->where('email_folder_id', $folderId)
                ->where('uid_validity', $uidValidity)
                ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();
            if (! $folder || ! $namespace
                || (string) $folder->path !== (string) $folderRun->folder_path) {
                throw new EmailProviderReconciliationReadException(
                    'reconciliation_store_namespace_stale',
                );
            }

            return [$run, $folderRun, $folder];
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    private function assertEnvelope(
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] EmailProviderReconciliationPeekedMessage $peeked,
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationFolder $folderRun,
        EmailFolder $folder,
        int $uidValidity,
        int $uid,
    ): void {
        $message = $peeked->message();
        $exact = (int) ($payload['account_id'] ?? 0) === (int) $run->account_id
            && (int) ($payload['provider_binding_version'] ?? 0) === (int) $run->provider_binding_version
            && (string) ($payload['mailbox'] ?? '') === (string) $folderRun->folder_path
            && (string) $message->getFolderPath() === (string) $folder->path
            && (int) ($payload['uid_validity'] ?? 0) === $uidValidity
            && (int) ($payload['imap_uid'] ?? 0) === $uid
            && (int) $message->getUid() === $uid
            && $message->getClient() === null
            && ($payload['require_exact_provider_identity'] ?? false) === true
            && ($payload['run_provider_reconciliation'] ?? true) === false;
        if (! $exact) {
            throw new EmailProviderReconciliationReadException(
                'reconciliation_store_envelope_mismatch',
            );
        }
    }
}
