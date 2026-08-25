<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Actions\ProjectHistoricalEmailReadBaseline;
use App\Modules\Email\DTOs\EmailPlacementCreateResult;
use App\Modules\Email\DTOs\EmailProviderReconciliationPeekedMessage;
use App\Modules\Email\DTOs\InboundAttachmentPersistenceResult;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\BodyNormalizer;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailCanonicalSelfMapper;
use App\Modules\Email\Services\EmailFolderProjector;
use App\Modules\Email\Services\EmailPrivateStorage;
use App\Modules\Email\Services\EmailProviderDraftSyncService;
use App\Modules\Email\Services\EmailProviderMessageIdentity;
use App\Modules\Email\Services\EmailProviderReconciliationReadException;
use App\Modules\Email\Services\EmailRawMessageSnapshot;
use App\Modules\Email\Services\EmailSentReconciliationService;
use App\Modules\Email\Services\HtmlSanitizer;
use App\Modules\Email\Services\ImapClient;
use App\Modules\Email\Services\InboundAttachmentPersister;
use App\Modules\Email\Support\EmailAccountProviderLock;
use App\Modules\Email\Support\EmailAccountProviderLockContext;
use App\Modules\Email\Support\EmailProviderPath;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StoreInboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 40;

    public int $maxExceptions = 10;

    /** @var array<int, int> */
    public array $backoff = [15, 30, 60];

    protected ?int $providerBindingVersion = null;

    private ?EmailProviderReconciliationPeekedMessage $preloadedProviderMessage = null;

    private bool $preloadedProviderMessageRequired = false;

    private bool $projectPreloadedHistoricalReadBaseline = false;

    private ?EmailPlacementCreateResult $preloadedPlacementResult = null;

    /**
     * @param  array  $payload  Structured inbound email data
     */
    public function __construct(#[\SensitiveParameter] public array $payload)
    {
        // New dispatches freeze the account binding even when an older caller
        // has not yet been taught to add it. A job deserialized from an older
        // payload does not run this constructor and therefore remains missing
        // the snapshot, which the handle path rejects before provider I/O.
        if (! isset($this->payload['provider_binding_version'])
            && isset($this->payload['account_id'])
            && ($account = EmailAccount::query()->find((int) $this->payload['account_id']))) {
            $this->payload['provider_binding_version'] = app(EmailAccountProviderRuntimeResolver::class)
                ->captureBindingVersion($account);
        }

        $this->onQueue('email');
    }

    /**
     * Lock releases count as queue attempts. Keep a bounded time window so an
     * inbound store can wait for the account fetch which dispatched it without
     * being failed by a worker-level --tries=1 default.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    /**
     * Attach one detached provider message for this synchronous invocation.
     *
     * The envelope deliberately cannot serialize, and the one-shot marker
     * prevents the same job object from falling back to a provider refetch if
     * it is accidentally invoked again after the sensitive object is cleared.
     */
    public function withPreloadedProviderMessage(
        #[\SensitiveParameter] EmailProviderReconciliationPeekedMessage $peeked,
        bool $projectHistoricalReadBaseline = false,
    ): self {
        if ($this->preloadedProviderMessageRequired || $this->preloadedProviderMessage !== null) {
            throw new \LogicException('A preloaded provider message may be attached only once.');
        }

        $this->preloadedProviderMessageRequired = true;
        $this->preloadedProviderMessage = $peeked;
        $this->projectPreloadedHistoricalReadBaseline = $projectHistoricalReadBaseline;

        return $this;
    }

    /**
     * Return the content-free placement disposition from the synchronous
     * reconciliation invocation. Ordinary queued ingestion never sets it.
     */
    public function preloadedPlacementResult(): ?EmailPlacementCreateResult
    {
        return $this->preloadedPlacementResult;
    }

    public function handle(
        InboundAttachmentPersister $attachmentPersister,
        EmailFolderProjector $folderProjector,
        EmailProviderDraftSyncService $providerDrafts,
        EmailSentReconciliationService $sentReconciliations,
        EmailPrivateStorage $privateStorage,
        EmailRawMessageSnapshot $rawSnapshots,
        ProjectHistoricalEmailReadBaseline $historicalReadBaselines,
        EmailCanonicalSelfMapper $canonicalSelfMapper,
    ): void {
        try {
            $this->assertPreloadedProviderEnvelope();
            $this->handleOnce(
                $attachmentPersister,
                $folderProjector,
                $providerDrafts,
                $sentReconciliations,
                $privateStorage,
                $rawSnapshots,
                $historicalReadBaselines,
                $canonicalSelfMapper,
            );
        } finally {
            // Detached headers/body/attachments must not survive the one
            // synchronous persistence call in a long-lived queue worker.
            $this->preloadedProviderMessage = null;
        }
    }

    private function handleOnce(
        InboundAttachmentPersister $attachmentPersister,
        EmailFolderProjector $folderProjector,
        EmailProviderDraftSyncService $providerDrafts,
        EmailSentReconciliationService $sentReconciliations,
        EmailPrivateStorage $privateStorage,
        EmailRawMessageSnapshot $rawSnapshots,
        ProjectHistoricalEmailReadBaseline $historicalReadBaselines,
        EmailCanonicalSelfMapper $canonicalSelfMapper,
    ): void {
        $accountId = (int) ($this->payload['account_id'] ?? 0);
        if ($accountId < 1) {
            return;
        }

        if (EmailAccountProviderLockContext::held($accountId)) {
            $this->handleUnderProviderLock(
                $attachmentPersister,
                $folderProjector,
                $providerDrafts,
                $sentReconciliations,
                $privateStorage,
                $rawSnapshots,
                $historicalReadBaselines,
                $canonicalSelfMapper,
            );

            return;
        }

        $providerLock = EmailAccountProviderLock::acquire($accountId, $this->timeout + 60);
        if (! $providerLock) {
            // A queued store commonly becomes visible before its account poll
            // releases the shared provider lease. That is normal contention,
            // not a security failure. Synchronous work still fails closed
            // because it has no durable queue on which to wait.
            if ($this->job !== null
                && ! $this->job instanceof \Illuminate\Queue\Jobs\SyncJob) {
                $this->release(EmailAccountProviderLock::RELEASE_AFTER_SECONDS);

                return;
            }

            throw new EmailProviderSecurityException('provider_work_locked');
        }

        try {
            $this->handleUnderProviderLock(
                $attachmentPersister,
                $folderProjector,
                $providerDrafts,
                $sentReconciliations,
                $privateStorage,
                $rawSnapshots,
                $historicalReadBaselines,
                $canonicalSelfMapper,
            );
        } finally {
            $providerLock->release();
        }
    }

    private function handleUnderProviderLock(
        InboundAttachmentPersister $attachmentPersister,
        EmailFolderProjector $folderProjector,
        EmailProviderDraftSyncService $providerDrafts,
        EmailSentReconciliationService $sentReconciliations,
        EmailPrivateStorage $privateStorage,
        EmailRawMessageSnapshot $rawSnapshots,
        ProjectHistoricalEmailReadBaseline $historicalReadBaselines,
        EmailCanonicalSelfMapper $canonicalSelfMapper,
    ): void {
        $account = EmailAccount::find($this->payload['account_id']);
        if (! $account) {
            return;
        }

        $this->providerBindingVersion = isset($this->payload['provider_binding_version'])
            ? (int) $this->payload['provider_binding_version']
            : null;
        if (! $this->providerBindingVersion || $this->providerBindingVersion < 1) {
            Log::notice('Inbound Email provider work was discarded because its binding snapshot is missing.', [
                'account_id' => $account->id,
                'reason' => 'provider_binding_snapshot_missing',
            ]);

            return;
        }

        if (app(EmailAccountProviderRuntimeResolver::class)->bindingVersion($account)
            !== $this->providerBindingVersion) {
            Log::notice('Inbound Email provider work was discarded after a binding change.', [
                'account_id' => $account->id,
                'reason' => 'provider_binding_stale',
            ]);

            return;
        }

        $uidValidity = (int) ($this->payload['uid_validity'] ?? $this->payload['imap_uid_validity'] ?? 0);
        $mailbox = EmailProviderPath::normalize(
            (string) ($this->payload['mailbox'] ?? 'INBOX'),
        );
        $this->payload['mailbox'] = $mailbox;
        $identity = [
            'account_id' => $this->payload['account_id'],
            'mailbox' => $mailbox,
            'imap_uid_validity' => $uidValidity,
            'imap_uid' => $this->payload['imap_uid'],
        ];
        $folder = $folderProjector->ensureFolderForMessage($account, $identity['mailbox'], $uidValidity);

        $existing = EmailMessage::withTrashed()
            ->where($identity)
            ->first();

        if ($existing) {
            $placement = null;
            if ($this->preloadedProviderMessage) {
                if (! $folder) {
                    throw new EmailProviderReconciliationReadException(
                        'reconciliation_pending_placement_missing',
                    );
                }

                $placement = DB::transaction(function () use (
                    $existing,
                    $folder,
                    $folderProjector,
                    $identity,
                    $uidValidity,
                ): EmailMailboxPlacement {
                    $lockedExisting = EmailMessage::withTrashed()
                        ->lockForUpdate()
                        ->find($existing->id);
                    if (! $lockedExisting) {
                        throw new EmailProviderReconciliationReadException(
                            'reconciliation_pending_message_missing',
                        );
                    }

                    $placement = $this->projectPlacement(
                        $folderProjector,
                        $lockedExisting,
                        $folder,
                        $this->placementPayload($lockedExisting->trashed(), $uidValidity),
                    );
                    if (! $placement || ! $this->preloadedPlacementResult) {
                        throw new EmailProviderReconciliationReadException(
                            'reconciliation_pending_placement_missing',
                        );
                    }

                    if ($this->preloadedPlacementResult->reconciliationPending()
                        && ! $lockedExisting->trashed()
                        && ! ($this->payload['is_oversize'] ?? false)) {
                        // Only a durable hidden Store marker authorizes artifact
                        // repair. An unrelated ACTIVE occurrence is immutable at
                        // this boundary, including its legacy raw reference.
                        $intendedRawPath = $this->rawStoragePath(
                            (int) $lockedExisting->account_id,
                            (string) $identity['mailbox'],
                            $uidValidity,
                            (int) $identity['imap_uid'],
                        );
                        if ((string) $lockedExisting->raw_path !== $intendedRawPath) {
                            $lockedExisting->forceFill(['raw_path' => $intendedRawPath])->save();
                        }
                    }

                    return $placement;
                }, 3);
            }

            if (! $existing->trashed() && $this->preloadedProviderMessage) {
                $existing->refresh();
                $attachmentResult = $this->preloadedPlacementResult?->reconciliationPending()
                    ? $this->repairPreloadedArtifacts(
                        $existing,
                        $account,
                        $identity,
                        $attachmentPersister,
                        $privateStorage,
                        $rawSnapshots,
                    )
                    : $this->verifyPreexistingArtifacts(
                        $existing,
                        $attachmentPersister,
                        $rawSnapshots,
                    );
                $this->assertStrictAttachmentResult($attachmentResult);
            }

            if (! $existing->trashed()
                && ! $this->preloadedProviderMessage
                && $folder
                && $folderProjector->available()
                && ! EmailMailboxPlacement::query()
                    ->where('email_message_id', $existing->id)
                    ->where('account_id', $account->id)
                    ->where('email_folder_id', $folder->id)
                    ->where('imap_uid_validity', $uidValidity)
                    ->where('imap_uid', $identity['imap_uid'])
                    ->exists()) {
                // A legacy/crashed row without its provider occurrence is not
                // evidence that local content is complete. Ordinary ingestion
                // must not bless it ACTIVE or run automation; reconciliation
                // can repair it through the strict preloaded pending seam.
                Log::notice('Inbound Email row without a placement was left fail-closed.', [
                    'account_id' => $account->id,
                    'email_message_id' => $existing->id,
                    'reason' => 'placement_missing_requires_reconciliation',
                ]);

                return;
            }

            if ($folder && ! $this->preloadedProviderMessage) {
                $placementPayload = $this->placementPayload($existing->trashed(), $uidValidity);
                $placement = $this->projectPlacement(
                    $folderProjector,
                    $existing,
                    $folder,
                    $placementPayload,
                );

            }

            if ($placement && $this->shouldRunProviderReconciliation()) {
                $providerDrafts->reconcilePlacement($placement);
                $sentReconciliations->reconcilePlacement($placement);
            }

            if ($existing->trashed()) {
                Log::info('Inbound email UID already exists as soft-deleted; skipping re-import.', $identity + [
                    'email_message_id' => $existing->id,
                ]);

                return;
            }

            if (! $this->preloadedProviderMessage
                || $this->preloadedPlacementResult?->reconciliationPending()) {
                $this->projectCanonicalSelfMap($canonicalSelfMapper, $existing);
            }

            Log::info('Inbound email UID already stored; skipping duplicate store.', $identity + [
                'email_message_id' => $existing->id,
            ]);

            if ($this->shouldRunInboundAutomation($account, $identity['mailbox'])) {
                ProcessInboundRules::dispatch($existing->id, $this->allowsProviderMutation());
            }

            return;
        }

        // Oversize: only store headers/meta, skip body & attachments
        $html = null;
        $text = null;
        $rawPath = null;
        $rawSnapshot = null;
        $attachments = [];

        if (! ($this->payload['is_oversize'] ?? false)) {
            // Fetch one exact provider message without leaving an IMAP connection open.
            $client = null;
            $strictProviderIdentity = (bool) ($this->payload['require_exact_provider_identity'] ?? false);

            try {
                $message = $this->preloadedProviderMessage?->message();
                if (! $message) {
                    $client = $this->makeImapClient($account);
                    $client->connect();
                    if ($strictProviderIdentity) {
                        $before = $client->folderState($identity['mailbox']);
                        if ((int) ($before['uid_validity'] ?? 0) !== $uidValidity) {
                            throw new \RuntimeException('Historical provider UID namespace changed before exact fetch.');
                        }
                    }
                    $message = $client->fetchByUid($this->payload['imap_uid'], $identity['mailbox']);

                    if ($strictProviderIdentity) {
                        $after = $client->folderState($identity['mailbox']);
                        if (! $message || (int) ($after['uid_validity'] ?? 0) !== $uidValidity) {
                            throw new \RuntimeException('Historical provider UID identity became stale during exact fetch.');
                        }
                    }
                }

                if ($message) {
                    $html = $message->getHTMLBody();
                    $text = $message->getTextBody();

                    // A real .eml must include the original header block and body.
                    try {
                        $raw = $rawSnapshots->serialize($message);
                        if ($raw === null) {
                            Log::warning('Raw save failed', [
                                'uid' => $this->payload['imap_uid'],
                                'reason' => 'snapshot_not_reparsable',
                            ]);
                        } else {
                            $candidateRawPath = $this->rawStoragePath(
                                (int) $account->id,
                                (string) $identity['mailbox'],
                                $uidValidity,
                                (int) $this->payload['imap_uid'],
                            );
                            if ($this->preloadedProviderMessage) {
                                // The deterministic intended path is committed
                                // with the hidden placement before any private
                                // bytes are written. A hard loss can therefore
                                // leave only a referenced, repairable file gap.
                                $rawPath = $candidateRawPath;
                                $rawSnapshot = $raw;
                            } else {
                                $stored = $privateStorage->put($candidateRawPath, $raw);
                                $rawPath = $stored ? $candidateRawPath : null;
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Raw save failed', [
                            'uid' => $this->payload['imap_uid'],
                            'exception' => $e::class,
                        ]);
                    }

                    $attachments = $message->getAttachments();
                }
            } catch (\Throwable $e) {
                if ($strictProviderIdentity) {
                    throw $e;
                }

                Log::warning('Failed to refetch full message', [
                    'account_id' => $account->id,
                    'uid' => $this->payload['imap_uid'],
                    'reason' => 'provider_read_failed',
                    'exception' => $e::class,
                ]);
            } finally {
                // Connection cleanup is best-effort and must not turn a stored message into a retry.
                try {
                    $client?->disconnect();
                } catch (\Throwable $e) {
                    Log::notice('Inbound IMAP disconnect failed.', [
                        'account_id' => $account->id,
                        'uid' => $this->payload['imap_uid'],
                        'exception' => $e::class,
                    ]);
                }
            }
        }

        $sanitized = HtmlSanitizer::sanitize($html);
        $textNormalized = $text ?: BodyNormalizer::toText($html);

        $messageAttributes = [
            'message_id' => $this->payload['message_id'] ?? null,
            'subject' => $this->payload['subject'] ?? null,
            'from_name' => $this->payload['from_name'] ?? null,
            'from_email' => $this->payload['from_email'] ?? null,
            'to_json' => $this->payload['to'] ?? [],
            'cc_json' => $this->payload['cc'] ?? [],
            'headers_json' => $this->payload['headers'] ?? [],
            'in_reply_to' => $this->payload['in_reply_to'] ?? null,
            'references' => $this->payload['references'] ?? null,
            'received_at' => $this->payload['received_at'] ?? now(),
            'size_bytes' => $this->payload['size_bytes'] ?? null,
            'is_oversize' => $this->payload['is_oversize'] ?? false,
            'state' => 'untriaged',
            'labels_json' => [],
            'body_html_sanitized' => $sanitized,
            'body_text' => $textNormalized,
            'raw_path' => $rawPath,
            'attachments_count' => 0,
            'checksum_sha1' => $this->payload['checksum_sha1'] ?? null,
        ];
        $persistLocalIdentity = function () use (
            $folder,
            $folderProjector,
            $historicalReadBaselines,
            $identity,
            $messageAttributes,
            $providerDrafts,
            $sentReconciliations,
            $uidValidity,
        ): array {
            $messageModel = $this->storeMessage(
                $identity,
                $messageAttributes,
                $historicalReadBaselines,
            );
            if (! $messageModel) {
                if ($this->preloadedProviderMessage) {
                    throw new EmailProviderReconciliationReadException(
                        'reconciliation_pending_message_missing',
                    );
                }

                return [null, null];
            }

            $placement = $folder
                ? $this->projectPlacement(
                    $folderProjector,
                    $messageModel,
                    $folder,
                    $this->placementPayload(false, $uidValidity),
                )
                : null;
            if ($this->preloadedProviderMessage
                && (! $placement || ! $this->preloadedPlacementResult)) {
                throw new EmailProviderReconciliationReadException(
                    'reconciliation_pending_placement_missing',
                );
            }

            if ($placement && $this->shouldRunProviderReconciliation()) {
                $providerDrafts->reconcilePlacement($placement);
                $sentReconciliations->reconcilePlacement($placement);
            }

            return [$messageModel, $placement];
        };

        // Preloaded reconciliation creates the content row, hidden placement,
        // and conversation identity as one local commit. Only then may private
        // bytes be written. Ordinary legacy ingestion retains its established
        // transaction boundaries.
        [$messageModel] = $this->preloadedProviderMessage
            ? DB::transaction($persistLocalIdentity, 3)
            : $persistLocalIdentity();

        if (! $messageModel) {
            return;
        }

        $reconciliationPlacementPending = $this->preloadedProviderMessage
            && $this->preloadedPlacementResult?->reconciliationPending();

        if ($reconciliationPlacementPending
            && ! ($this->payload['is_oversize'] ?? false)) {
            try {
                $rawStored = $rawPath !== null
                    && $rawSnapshot !== null
                    && ($this->storedFileMatches($rawPath, $rawSnapshot)
                        || ($privateStorage->put($rawPath, $rawSnapshot)
                            && $this->storedFileMatches($rawPath, $rawSnapshot)));
            } catch (\Throwable) {
                $rawStored = false;
            }

            if (! $rawStored) {
                throw new EmailProviderReconciliationReadException(
                    'reconciliation_raw_persistence_failed',
                );
            }
        }

        if ($messageModel->wasRecentlyCreated || $reconciliationPlacementPending) {
            $attachmentResult = $attachmentPersister->persistWithResult(
                $messageModel,
                $attachments,
                referencePathBeforeWrite: $this->preloadedProviderMessage !== null,
            );
            $messageModel->forceFill([
                'attachments_count' => $messageModel->attachments()->count(),
            ])->save();
            $this->assertStrictAttachmentResult($attachmentResult);
        }

        // The source occurrence, attachment rows, and placement now form one complete local write.
        // Canonical expansion is schema-safe and can only create/refresh this source's self-map.
        if (! $this->preloadedProviderMessage || $reconciliationPlacementPending) {
            $this->projectCanonicalSelfMap($canonicalSelfMapper, $messageModel->fresh());
        }

        // Provider deletion is explicit. The legacy global switch applies only to migrated legacy
        // Ticket-ingest accounts, so normal Mail client accounts keep provider messages intact.
        $globalDelete = \App\Models\Settings\CommonSetting::where('type', 'emailhub')
            ->where('name', 'delete_on_success')
            ->value('value') === '1';

        $shouldDelete = $this->allowsProviderMutation()
            && $this->shouldRunInboundAutomation($account, $identity['mailbox'])
            && (($account->delete_policy === 'auto_delete') || ($account->delete_policy === 'legacy_default' && $globalDelete));

        if ($shouldDelete && $messageModel->wasRecentlyCreated) {
            $deleteClient = null;

            try {
                $deleteClient = $this->makeImapClient($account);
                $deleteClient->connect();
                $deleteClient->deleteByUid($messageModel->imap_uid, $identity['mailbox']);
            } catch (\Throwable $e) {
                Log::warning('Auto-delete failed', [
                    'account_id' => $account->id,
                    'uid' => $messageModel->imap_uid,
                    'reason' => 'provider_delete_failed',
                    'exception' => $e::class,
                ]);
            } finally {
                try {
                    $deleteClient?->disconnect();
                } catch (\Throwable $e) {
                    Log::notice('Inbound delete IMAP disconnect failed.', [
                        'account_id' => $account->id,
                        'uid' => $messageModel->imap_uid,
                        'exception' => $e::class,
                    ]);
                }
            }
        }

        if ($this->shouldRunInboundAutomation($account, $identity['mailbox'])) {
            ProcessInboundRules::dispatch($messageModel->id, $this->allowsProviderMutation());
        }
    }

    /**
     * Complete a locally interrupted preloaded store without reconnecting to
     * the provider. Paths are deterministic and attachment persistence is
     * idempotent, so every redelivery converges on the same local artifacts.
     *
     * @param  array{account_id:mixed,mailbox:mixed,imap_uid_validity:int,imap_uid:mixed}  $identity
     */
    private function repairPreloadedArtifacts(
        EmailMessage $existing,
        EmailAccount $account,
        array $identity,
        InboundAttachmentPersister $attachmentPersister,
        EmailPrivateStorage $privateStorage,
        EmailRawMessageSnapshot $rawSnapshots,
    ): InboundAttachmentPersistenceResult {
        if (($this->payload['is_oversize'] ?? false) === true) {
            $actualCount = $existing->attachments()->count();
            if ((int) $existing->attachments_count !== $actualCount) {
                $existing->forceFill(['attachments_count' => $actualCount])->save();
            }

            return new InboundAttachmentPersistenceResult(0, 0, 0, []);
        }

        $message = $this->preloadedProviderMessage?->message();
        if (! $message) {
            throw new EmailProviderReconciliationReadException(
                'reconciliation_preloaded_message_missing',
            );
        }

        $html = $message->getHTMLBody();
        $text = $message->getTextBody();
        $updates = [];
        if (blank($existing->body_html_sanitized) && filled($html)) {
            $updates['body_html_sanitized'] = HtmlSanitizer::sanitize($html);
        }
        if (blank($existing->body_text)) {
            $normalized = $text ?: BodyNormalizer::toText($html);
            if (filled($normalized)) {
                $updates['body_text'] = $normalized;
            }
        }

        $candidateRawPath = $this->rawStoragePath(
            (int) $account->id,
            (string) $identity['mailbox'],
            (int) $identity['imap_uid_validity'],
            (int) $identity['imap_uid'],
        );
        try {
            $raw = $rawSnapshots->serialize($message);
            if ($raw === null
                || (! $this->storedFileMatches($candidateRawPath, $raw)
                    && (! $privateStorage->put($candidateRawPath, $raw)
                        || ! $this->storedFileMatches($candidateRawPath, $raw)))) {
                throw new \RuntimeException('The raw snapshot could not be verified.');
            }
            if ((string) $existing->raw_path !== $candidateRawPath) {
                $updates['raw_path'] = $candidateRawPath;
            }
        } catch (\Throwable $exception) {
            Log::warning('Raw save failed during local inbound resume.', [
                'account_id' => $account->id,
                'uid' => $identity['imap_uid'],
                'reason' => 'snapshot_resume_failed',
                'exception' => $exception::class,
            ]);

            throw new EmailProviderReconciliationReadException(
                'reconciliation_raw_persistence_failed',
            );
        }

        $attachmentResult = $attachmentPersister->persistWithResult(
            $existing,
            $message->getAttachments(),
            referencePathBeforeWrite: true,
        );
        $updates['attachments_count'] = $existing->attachments()->count();
        if ($updates !== []) {
            $existing->forceFill($updates)->save();
        }

        return $attachmentResult;
    }

    /**
     * Attest an unrelated active occurrence without repairing or normalizing it.
     *
     * PREEXISTING is a race outcome, not Store-owned crash evidence. Its raw
     * reference, private bytes, attachment rows, and attachment bytes therefore
     * remain immutable even when verification fails. A separately governed
     * recovery flow may repair active content later.
     */
    private function verifyPreexistingArtifacts(
        EmailMessage $existing,
        InboundAttachmentPersister $attachmentPersister,
        EmailRawMessageSnapshot $rawSnapshots,
    ): InboundAttachmentPersistenceResult {
        $message = $this->preloadedProviderMessage?->message();
        if (! $message) {
            throw new EmailProviderReconciliationReadException(
                'reconciliation_preloaded_message_missing',
            );
        }

        if (($this->payload['is_oversize'] ?? false) === true) {
            if (filled($existing->raw_path)
                || (int) $existing->attachments_count !== 0
                || $existing->attachments()->exists()) {
                throw new EmailProviderReconciliationReadException(
                    'reconciliation_store_artifacts_incomplete',
                );
            }

            return new InboundAttachmentPersistenceResult(0, 0, 0, []);
        }

        $raw = $rawSnapshots->serialize($message);
        $rawPath = str_replace('\\', '/', trim((string) $existing->raw_path));
        if ($raw === null
            || $rawPath === ''
            || ! str_starts_with($rawPath, 'email/raw/')
            || str_contains('/'.$rawPath.'/', '/../')
            || ! $this->storedFileMatches($rawPath, $raw)) {
            throw new EmailProviderReconciliationReadException(
                'reconciliation_store_artifacts_incomplete',
            );
        }

        return $attachmentPersister->verifyWithResult(
            $existing,
            $message->getAttachments(),
        );
    }

    private function storeMessage(
        array $identity,
        array $attributes,
        ProjectHistoricalEmailReadBaseline $historicalReadBaselines,
    ): ?EmailMessage {
        return DB::transaction(function () use (
            $attributes,
            $historicalReadBaselines,
            $identity,
        ): ?EmailMessage {
            // The account row serializes local message-ID creation with
            // no-access -> View baseline establishment. Provider reads and
            // private-file writes happen before this short critical section.
            $account = EmailAccount::query()->lockForUpdate()->find($identity['account_id']);

            if (! $account) {
                return null;
            }

            try {
                // Reconciliation is create-only. If another ingestion path won
                // the race, its active message projection must not be rewritten
                // before the placement disposition is known.
                $message = $this->preloadedProviderMessage
                    ? EmailMessage::query()->create($identity + $attributes)
                    : EmailMessage::updateOrCreate($identity, $attributes);
            } catch (QueryException $exception) {
                if (! $this->isDuplicateKeyException($exception)) {
                    throw $exception;
                }

                $message = EmailMessage::withTrashed()
                    ->where($identity)
                    ->first();

                if (! $message) {
                    throw $exception;
                }

                Log::info('Inbound email UID already stored by another worker; recovered duplicate race.', $identity + [
                    'email_message_id' => $message->id,
                    'trashed' => $message->trashed(),
                ]);

                if ($message->trashed()) {
                    return null;
                }
            }

            // Historical cache rows become read-for-me in the same commit as
            // their local ID. A normal ingestion race never gets overwritten.
            if ($message->wasRecentlyCreated
                && (isset($this->payload['historical_import_run_id'])
                    || $this->projectPreloadedHistoricalReadBaseline)) {
                $historicalReadBaselines->handle($account, $message);
            }

            return $message;
        }, 3);
    }

    private function isDuplicateKeyException(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $message = $exception->getMessage();

        return $sqlState === '23000'
            && (
                $driverCode === '1062'
                || str_contains($message, 'uniq_account_mailbox_uid')
                || str_contains($message, 'em_msg_uid_ns_uq')
                || str_contains($message, 'UNIQUE constraint failed')
            );
    }

    private function projectCanonicalSelfMap(
        EmailCanonicalSelfMapper $canonicalSelfMapper,
        EmailMessage $message,
    ): void {
        try {
            $canonicalSelfMapper->map($message);
        } catch (\Throwable $exception) {
            // Canonical projection is additive and rebuildable. Never turn a successfully stored
            // provider occurrence into a blind inbound retry; the bounded backfill/audit repairs it.
            Log::warning('Email canonical self-map projection failed.', [
                'email_message_id' => $message->id,
                'reason' => 'canonical_self_map_failed',
                'exception' => $exception::class,
            ]);
        }
    }

    private function shouldRunInboundAutomation(EmailAccount $account, string $mailbox): bool
    {
        if (($this->payload['run_inbound_rules'] ?? null) !== null) {
            return (bool) $this->payload['run_inbound_rules'];
        }

        return EmailFolder::inferRole($mailbox) === EmailFolder::ROLE_INBOX
            && $account->allowsTicketIngress();
    }

    private function assertPreloadedProviderEnvelope(): void
    {
        if ($this->preloadedProviderMessageRequired && ! $this->preloadedProviderMessage) {
            throw new EmailProviderReconciliationReadException(
                'reconciliation_preloaded_message_missing',
            );
        }
        if (! $this->preloadedProviderMessage) {
            return;
        }

        $envelope = $this->preloadedProviderMessage->payload();
        $message = $this->preloadedProviderMessage->message();
        try {
            $payloadMailbox = EmailProviderPath::normalize(
                (string) ($this->payload['mailbox'] ?? ''),
            );
            $envelopeMailbox = EmailProviderPath::normalize(
                (string) ($envelope['mailbox'] ?? ''),
            );
            $messageMailbox = EmailProviderPath::normalize(
                (string) $message->getFolderPath(),
            );
            $this->payload['mailbox'] = $payloadMailbox;
        } catch (\InvalidArgumentException) {
            throw new EmailProviderReconciliationReadException(
                'reconciliation_preloaded_envelope_mismatch',
            );
        }
        $payloadUidValidity = (int) ($this->payload['uid_validity']
            ?? $this->payload['imap_uid_validity']
            ?? 0);
        $envelopeUidValidity = (int) ($envelope['uid_validity']
            ?? $envelope['imap_uid_validity']
            ?? 0);
        $exact = (int) ($this->payload['account_id'] ?? 0) > 0
            && (int) ($this->payload['account_id'] ?? 0) === (int) ($envelope['account_id'] ?? 0)
            && (int) ($this->payload['provider_binding_version'] ?? 0) > 0
            && (int) ($this->payload['provider_binding_version'] ?? 0)
                === (int) ($envelope['provider_binding_version'] ?? 0)
            && $payloadMailbox === $envelopeMailbox
            && $messageMailbox === $payloadMailbox
            && $payloadUidValidity > 0
            && $payloadUidValidity === $envelopeUidValidity
            && (int) ($this->payload['imap_uid'] ?? 0) > 0
            && (int) ($this->payload['imap_uid'] ?? 0) === (int) ($envelope['imap_uid'] ?? 0)
            && (int) $message->getUid() === (int) ($this->payload['imap_uid'] ?? 0)
            && (int) ($this->payload['size_bytes'] ?? 0) === (int) ($envelope['size_bytes'] ?? 0)
            && (bool) ($this->payload['is_oversize'] ?? false)
                === (bool) ($envelope['is_oversize'] ?? false)
            && $message->getClient() === null
            && ($this->payload['require_exact_provider_identity'] ?? false) === true
            && ($this->payload['run_provider_reconciliation'] ?? true) === false
            && ($this->payload['allow_provider_mutation'] ?? null) === false;
        if (! $exact) {
            throw new EmailProviderReconciliationReadException(
                'reconciliation_preloaded_envelope_mismatch',
            );
        }
    }

    private function shouldRunProviderReconciliation(): bool
    {
        return (bool) ($this->payload['run_provider_reconciliation'] ?? true);
    }

    private function assertStrictAttachmentResult(
        InboundAttachmentPersistenceResult $result,
    ): void {
        if ($this->preloadedProviderMessage && $result->hasFailures()) {
            throw new EmailProviderReconciliationReadException(
                'reconciliation_attachment_persistence_failed',
            );
        }
    }

    private function rawStoragePath(
        int $accountId,
        string $mailbox,
        int $uidValidity,
        int $uid,
    ): string {
        return sprintf(
            'email/raw/v2/%d/%s/%d/%d.eml',
            $accountId,
            hash('sha256', $mailbox),
            max(0, $uidValidity),
            $uid,
        );
    }

    /**
     * Verify exact PEEK-derived bytes before a reconciliation retry trusts an
     * existing final path that may have survived an interrupted write.
     */
    private function storedFileMatches(string $path, string $expected): bool
    {
        $stream = null;

        try {
            $stream = Storage::disk('local')->readStream($path);
            if (! is_resource($stream)) {
                return false;
            }

            $digest = hash_init('sha256');
            $readBytes = hash_update_stream($digest, $stream);

            return $readBytes === strlen($expected)
                && hash_equals(hash('sha256', $expected), hash_final($digest));
        } catch (\Throwable) {
            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function allowsProviderMutation(): bool
    {
        // Missing and legacy-serialized payloads never gain new provider-write
        // authority. Every live producer must opt in explicitly.
        return ($this->payload['allow_provider_mutation'] ?? null) === true;
    }

    protected function makeImapClient(EmailAccount $account): ImapClient
    {
        return app()->makeWith(ImapClient::class, [
            'account' => $account,
            'expectedProviderBindingVersion' => (int) $this->providerBindingVersion,
        ]);
    }

    private function placementPayload(bool $hidden, int $uidValidity): array
    {
        $payload = $this->payload;

        if ($uidValidity > 0) {
            $payload['uid_validity'] = $uidValidity;
        } else {
            unset($payload['uid_validity'], $payload['imap_uid_validity']);
        }

        if ($hidden && ! array_key_exists('sync_status', $payload)) {
            $payload['sync_status'] = \App\Modules\Email\Models\EmailMailboxPlacement::SYNC_SHADOW;
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function projectPlacement(
        EmailFolderProjector $folderProjector,
        EmailMessage $message,
        EmailFolder $folder,
        array $payload,
    ): ?\App\Modules\Email\Models\EmailMailboxPlacement {
        if (! $this->preloadedProviderMessage) {
            $identities = app(EmailProviderMessageIdentity::class);
            $providerIdentity = $identities->forProviderPayload($payload);
            $persistedIdentity = $identities->forMessage($message);
            $strongIdentity = is_string($providerIdentity)
                && is_string($persistedIdentity)
                && hash_equals($providerIdentity, $persistedIdentity)
                    ? $providerIdentity
                    : null;

            return $folderProjector->upsertProviderObservedPlacement(
                $message,
                $folder,
                $payload,
                $strongIdentity,
            );
        }

        $result = $folderProjector->createPlacementIfMissing($message, $folder, $payload);
        $this->preloadedPlacementResult = $result;

        return $result?->placement;
    }
}
