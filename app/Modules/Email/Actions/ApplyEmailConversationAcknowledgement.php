<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailConversationActionItem;
use App\Modules\Email\Models\EmailConversationActionRun;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Services\EmailConversationAcknowledgementBoundary;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApplyEmailConversationAcknowledgement
{
    private const CLAIM_SECONDS = 60;

    private const ITEMS_PER_INVOCATION = 25;

    public function __construct(
        private readonly EmailConversationAcknowledgementBoundary $boundary,
        private readonly SetEmailUnreadForMe $setUnreadForMe,
        private readonly RecordEmailRemoteOperation $recordRemoteOperation,
    ) {}

    public function handle(
        EmailConversationActionRun $run,
        User $actor,
    ): EmailConversationActionRun {
        $this->boundary->assertAvailable();
        $run = $this->startOrResume($run, $actor);

        if (in_array($run->status, [
            EmailConversationActionRun::STATUS_APPLIED,
            EmailConversationActionRun::STATUS_STALE,
            EmailConversationActionRun::STATUS_FAILED,
            EmailConversationActionRun::STATUS_CANCELLED,
        ], true)) {
            return $run->load('items.remoteOperation');
        }

        foreach (range(1, self::ITEMS_PER_INVOCATION) as $_) {
            $claimed = $this->claimNextItem((int) $run->id);
            if (! $claimed) {
                break;
            }

            $this->applyClaimedItem((int) $run->id, $claimed['item_id'], $claimed['token'], $actor);
        }

        $this->refreshProviderResults((int) $run->id);

        return $this->summarize((int) $run->id)->load('items.remoteOperation');
    }

    private function startOrResume(
        EmailConversationActionRun $run,
        User $actor,
    ): EmailConversationActionRun {
        return DB::transaction(function () use ($actor, $run): EmailConversationActionRun {
            $locked = EmailConversationActionRun::query()->lockForUpdate()->findOrFail($run->id);
            $this->assertActorOwnsRun($locked, $actor);

            if (! $this->scopeFingerprintMatches($locked)) {
                $locked->forceFill([
                    'status' => EmailConversationActionRun::STATUS_FAILED,
                    'error_code' => 'scope_evidence_mismatch',
                    'completed_at' => now(),
                ])->save();

                return $locked->fresh();
            }

            if ($locked->status === EmailConversationActionRun::STATUS_PREVIEWED
                && $locked->expires_at?->isPast()) {
                $locked->forceFill([
                    'status' => EmailConversationActionRun::STATUS_STALE,
                    'error_code' => 'preview_expired',
                    'completed_at' => now(),
                ])->save();

                return $locked->fresh();
            }

            if (in_array($locked->status, [
                EmailConversationActionRun::STATUS_PREVIEWED,
                EmailConversationActionRun::STATUS_APPLYING,
                EmailConversationActionRun::STATUS_PARTIAL,
            ], true)) {
                $locked->forceFill([
                    'status' => EmailConversationActionRun::STATUS_APPLYING,
                    'started_at' => $locked->started_at ?? now(),
                    'completed_at' => null,
                ])->save();
            }

            return $locked->fresh();
        });
    }

    /** @return array{item_id: int, token: string}|null */
    private function claimNextItem(int $runId): ?array
    {
        return DB::transaction(function () use ($runId): ?array {
            $now = now();
            $item = EmailConversationActionItem::query()
                ->where('run_id', $runId)
                ->where(function ($effects): void {
                    $effects
                        ->where('personal_status', EmailConversationActionItem::PERSONAL_PENDING)
                        ->orWhere(function ($provider): void {
                            $provider
                                ->where('provider_status', EmailConversationActionItem::PROVIDER_PENDING)
                                ->whereNull('email_remote_operation_id');
                        });
                })
                ->where(function ($claims) use ($now): void {
                    $claims
                        ->whereNull('claim_token')
                        ->orWhere('claim_expires_at', '<=', $now);
                })
                ->orderBy('ordinal')
                ->lockForUpdate()
                ->first();

            if (! $item) {
                return null;
            }

            $token = hash('sha256', (string) Str::uuid());
            $item->forceFill([
                'claim_token' => $token,
                'claimed_at' => $now,
                'claim_expires_at' => $now->copy()->addSeconds(self::CLAIM_SECONDS),
            ])->save();

            return ['item_id' => (int) $item->id, 'token' => $token];
        });
    }

    private function applyClaimedItem(
        int $runId,
        int $itemId,
        string $claimToken,
        User $actor,
    ): void {
        DB::transaction(function () use ($actor, $claimToken, $itemId, $runId): void {
            $run = EmailConversationActionRun::query()->lockForUpdate()->findOrFail($runId);
            $item = EmailConversationActionItem::query()->lockForUpdate()->findOrFail($itemId);

            if ((int) $item->run_id !== (int) $run->id
                || ! hash_equals((string) $item->claim_token, $claimToken)
                || $item->claim_expires_at?->isPast()
                || $run->status !== EmailConversationActionRun::STATUS_APPLYING) {
                return;
            }

            $this->assertActorOwnsRun($run, $actor);
            $currentActor = User::query()->find($actor->id);
            $account = EmailAccount::query()->find($item->account_id);
            $conversation = EmailConversation::query()->find($item->email_conversation_id);
            $message = EmailMessage::withTrashed()->find($item->email_message_id);
            $placement = EmailMailboxPlacement::query()->find($item->email_mailbox_placement_id);
            $folder = EmailFolder::query()->find($item->email_folder_id);

            if (! $currentActor || ! $account || ! $conversation || ! $message || ! $placement || ! $folder
                || ! $this->itemFingerprintMatches($item)) {
                $this->markCommonOutcome($item, EmailConversationActionItem::PERSONAL_STALE,
                    EmailConversationActionItem::PROVIDER_STALE, 'snapshot_resource_stale');

                return;
            }

            try {
                $this->boundary->authorize($currentActor, $account, MailboxAccess::VIEW);
            } catch (AuthorizationException) {
                $this->markCommonOutcome($item, EmailConversationActionItem::PERSONAL_DENIED,
                    EmailConversationActionItem::PROVIDER_DENIED, 'mailbox_view_denied');

                return;
            }

            if (! $this->sourceStillMatches($item, $account, $conversation, $message, $placement, $folder)) {
                $this->markCommonOutcome($item, EmailConversationActionItem::PERSONAL_STALE,
                    EmailConversationActionItem::PROVIDER_STALE, 'placement_snapshot_stale');

                return;
            }

            $this->applyPersonalEffect($item, $currentActor, $account, $message);
            $this->applyProviderEffect($item, $currentActor, $account, $placement, $folder);

            $item->forceFill([
                'claim_token' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
                'completed_at' => now(),
            ])->save();
        });
    }

    private function applyPersonalEffect(
        EmailConversationActionItem $item,
        User $actor,
        EmailAccount $account,
        EmailMessage $message,
    ): void {
        if ($item->personal_status !== EmailConversationActionItem::PERSONAL_PENDING) {
            return;
        }

        try {
            $currentEpoch = $this->boundary->accessEpoch($actor, $account);
            $currentUnread = $this->boundary->personalUnread($actor, $message);

            if ($currentEpoch !== (int) $item->access_epoch
                || $currentUnread !== (bool) $item->personal_before) {
                $item->forceFill([
                    'personal_status' => EmailConversationActionItem::PERSONAL_STALE,
                    'personal_reason_code' => 'personal_epoch_or_state_stale',
                ])->save();

                return;
            }

            $this->setUnreadForMe->handle($actor, $message, (bool) $item->personal_target);
            $item->forceFill([
                'personal_status' => EmailConversationActionItem::PERSONAL_APPLIED,
                'personal_reason_code' => null,
                'personal_applied_at' => now(),
            ])->save();
        } catch (AuthorizationException) {
            $item->forceFill([
                'personal_status' => EmailConversationActionItem::PERSONAL_DENIED,
                'personal_reason_code' => 'personal_authority_denied',
            ])->save();
        } catch (Throwable) {
            $item->forceFill([
                'personal_status' => EmailConversationActionItem::PERSONAL_FAILED,
                'personal_reason_code' => 'personal_apply_failed',
            ])->save();
        }
    }

    private function applyProviderEffect(
        EmailConversationActionItem $item,
        User $actor,
        EmailAccount $account,
        EmailMailboxPlacement $placement,
        EmailFolder $folder,
    ): void {
        if ($item->provider_status !== EmailConversationActionItem::PROVIDER_PENDING
            || $item->email_remote_operation_id !== null) {
            return;
        }

        try {
            $this->boundary->authorize($actor, $account, MailboxAccess::ORGANIZE);
        } catch (AuthorizationException) {
            $item->forceFill([
                'provider_status' => EmailConversationActionItem::PROVIDER_DENIED,
                'provider_reason_code' => 'mailbox_organize_denied',
            ])->save();

            return;
        }

        try {
            if ($this->boundary->providerBindingVersion($account) !== (int) $item->provider_binding_version
                || (bool) $placement->provider_seen !== (bool) $item->provider_before) {
                $item->forceFill([
                    'provider_status' => EmailConversationActionItem::PROVIDER_STALE,
                    'provider_reason_code' => 'provider_state_or_binding_stale',
                ])->save();

                return;
            }

            $operation = $this->recordRemoteOperation->pending(
                account: $account,
                operationType: PerformEmailRemoteOperation::MARK_SEEN,
                idempotencyKey: "conversation-ack:{$item->run_id}:{$item->id}:mark-seen",
                actor: $actor,
                folder: $folder,
                placement: $placement,
                request: [
                    'source_folder_path' => (string) $placement->folder_path,
                    'placement_sync_version' => (int) $placement->sync_version,
                    'placement_imap_uid' => (int) $placement->imap_uid,
                    'placement_uid_validity' => (int) $placement->imap_uid_validity,
                    'target_state' => ['provider_seen' => true],
                ],
            );

            $item->forceFill([
                'email_remote_operation_id' => $operation->id,
                'provider_status' => $this->providerItemStatus($operation),
                'provider_reason_code' => null,
                'provider_reserved_at' => now(),
            ])->save();
        } catch (ValidationException) {
            $item->forceFill([
                'provider_status' => EmailConversationActionItem::PROVIDER_CONFLICTED,
                'provider_reason_code' => 'provider_operation_conflict',
            ])->save();
        } catch (Throwable) {
            // The personal effect has already committed inside this enclosing
            // item transaction and is not rolled back or relabelled as remote success.
            $item->forceFill([
                'provider_status' => EmailConversationActionItem::PROVIDER_FAILED,
                'provider_reason_code' => 'provider_reservation_failed',
            ])->save();
        }
    }

    private function sourceStillMatches(
        EmailConversationActionItem $item,
        EmailAccount $account,
        EmailConversation $conversation,
        EmailMessage $message,
        EmailMailboxPlacement $placement,
        EmailFolder $folder,
    ): bool {
        return ! $message->trashed()
            && $account->is_active
            && $conversation->status === EmailConversation::STATUS_ACTIVE
            && (int) $conversation->account_id === (int) $item->account_id
            && (int) $message->account_id === (int) $item->account_id
            && (int) $placement->account_id === (int) $item->account_id
            && (int) $placement->email_conversation_id === (int) $item->email_conversation_id
            && (int) $placement->email_message_id === (int) $item->email_message_id
            && (int) $placement->email_folder_id === (int) $item->email_folder_id
            && (int) $folder->account_id === (int) $item->account_id
            && (int) $placement->uid_namespace_id === (int) $item->uid_namespace_id
            && (int) $placement->imap_uid_validity === (int) $item->imap_uid_validity
            && (int) $placement->imap_uid === (int) $item->imap_uid
            && (int) $placement->sync_version === (int) $item->placement_sync_version
            && $placement->local_state === EmailMailboxPlacement::LOCAL_ACTIVE
            && $placement->provider_missing_at === null
            && hash_equals(
                (string) $item->source_fingerprint,
                $this->boundary->sourceFingerprint($placement, $message),
            );
    }

    private function itemFingerprintMatches(EmailConversationActionItem $item): bool
    {
        $snapshot = [
            'account_id' => (int) $item->account_id,
            'access_epoch' => (int) $item->access_epoch,
            'conversation_id' => (int) $item->email_conversation_id,
            'folder_id' => (int) $item->email_folder_id,
            'message_id' => (int) $item->email_message_id,
            'personal_before' => (bool) $item->personal_before,
            'personal_selected' => (bool) $item->personal_selected,
            'personal_target' => (bool) $item->personal_target,
            'placement_id' => (int) $item->email_mailbox_placement_id,
            'provider_before' => (bool) $item->provider_before,
            'provider_binding_version' => (int) $item->provider_binding_version,
            'provider_selected' => (bool) $item->provider_selected,
            'provider_target' => (bool) $item->provider_target,
            'source_fingerprint' => (string) $item->source_fingerprint,
            'sync_version' => (int) $item->placement_sync_version,
            'uid' => (int) $item->imap_uid,
            'uid_namespace_id' => (int) $item->uid_namespace_id,
            'uid_validity' => (int) $item->imap_uid_validity,
        ];

        return hash_equals(
            (string) $item->item_fingerprint,
            $this->boundary->itemFingerprint($snapshot),
        );
    }

    private function scopeFingerprintMatches(EmailConversationActionRun $run): bool
    {
        $fingerprints = $run->items()
            ->orderBy('ordinal')
            ->lockForUpdate()
            ->pluck('item_fingerprint')
            ->all();
        $expected = hash('sha256', json_encode([
            'scope_kind' => (string) $run->scope_kind,
            'active_account_id' => $run->active_email_account_id,
            'active_conversation_id' => $run->active_email_conversation_id,
            'target_personal_unread' => (bool) $run->target_personal_unread,
            'provider_seen_requested' => (bool) $run->provider_seen_requested,
            'items' => $fingerprints,
        ], JSON_THROW_ON_ERROR));

        return hash_equals((string) $run->scope_fingerprint, $expected)
            && count($fingerprints) === (int) $run->item_count;
    }

    private function markCommonOutcome(
        EmailConversationActionItem $item,
        string $personalStatus,
        string $providerStatus,
        string $reason,
    ): void {
        $item->forceFill([
            'personal_status' => $item->personal_status === EmailConversationActionItem::PERSONAL_PENDING
                ? $personalStatus
                : $item->personal_status,
            'personal_reason_code' => $item->personal_status === EmailConversationActionItem::PERSONAL_PENDING
                ? $reason
                : $item->personal_reason_code,
            'provider_status' => $item->provider_status === EmailConversationActionItem::PROVIDER_PENDING
                ? $providerStatus
                : $item->provider_status,
            'provider_reason_code' => $item->provider_status === EmailConversationActionItem::PROVIDER_PENDING
                ? $reason
                : $item->provider_reason_code,
            'claim_token' => null,
            'claimed_at' => null,
            'claim_expires_at' => null,
            'completed_at' => now(),
        ])->save();
    }

    private function refreshProviderResults(int $runId): void
    {
        EmailConversationActionItem::query()
            ->where('run_id', $runId)
            ->whereNotNull('email_remote_operation_id')
            ->orderBy('id')
            ->each(function (EmailConversationActionItem $item): void {
                $operation = EmailRemoteOperation::query()->find($item->email_remote_operation_id);
                $status = $operation
                    ? $this->providerItemStatus($operation)
                    : EmailConversationActionItem::PROVIDER_FAILED;

                if ($item->provider_status !== $status) {
                    $item->forceFill([
                        'provider_status' => $status,
                        'provider_reason_code' => $operation ? null : 'provider_operation_missing',
                    ])->save();
                }
            });
    }

    private function providerItemStatus(EmailRemoteOperation $operation): string
    {
        return match ($operation->status) {
            EmailRemoteOperation::STATUS_SUCCEEDED => EmailConversationActionItem::PROVIDER_SUCCEEDED,
            EmailRemoteOperation::STATUS_PENDING,
            EmailRemoteOperation::STATUS_RUNNING => EmailConversationActionItem::PROVIDER_PENDING,
            EmailRemoteOperation::STATUS_CANCELLED,
            EmailRemoteOperation::STATUS_SUPERSEDED => EmailConversationActionItem::PROVIDER_CONFLICTED,
            default => EmailConversationActionItem::PROVIDER_FAILED,
        };
    }

    private function summarize(int $runId): EmailConversationActionRun
    {
        return DB::transaction(function () use ($runId): EmailConversationActionRun {
            $run = EmailConversationActionRun::query()->lockForUpdate()->findOrFail($runId);
            $items = EmailConversationActionItem::query()->where('run_id', $runId)->get();
            $personalEffects = $items->where('personal_selected', true);
            $providerEffects = $items->where('provider_selected', true);
            $personalApplied = $personalEffects
                ->where('personal_status', EmailConversationActionItem::PERSONAL_APPLIED)->count();
            $providerPending = $providerEffects
                ->where('provider_status', EmailConversationActionItem::PROVIDER_PENDING)
                ->whereNotNull('email_remote_operation_id')->count();
            $providerSucceeded = $providerEffects
                ->where('provider_status', EmailConversationActionItem::PROVIDER_SUCCEEDED)->count();
            $deniedEffects = $personalEffects
                ->where('personal_status', EmailConversationActionItem::PERSONAL_DENIED)->count()
                + $providerEffects
                    ->where('provider_status', EmailConversationActionItem::PROVIDER_DENIED)->count();
            $staleEffects = $personalEffects
                ->where('personal_status', EmailConversationActionItem::PERSONAL_STALE)->count()
                + $providerEffects
                    ->where('provider_status', EmailConversationActionItem::PROVIDER_STALE)->count();
            $failedEffects = $personalEffects
                ->where('personal_status', EmailConversationActionItem::PERSONAL_FAILED)->count()
                + $providerEffects->filter(fn (EmailConversationActionItem $item): bool => in_array(
                    $item->provider_status,
                    [
                        EmailConversationActionItem::PROVIDER_FAILED,
                        EmailConversationActionItem::PROVIDER_CONFLICTED,
                    ],
                    true,
                ))->count();
            $denied = $items->filter(fn (EmailConversationActionItem $item): bool => ($item->personal_selected
                && $item->personal_status === EmailConversationActionItem::PERSONAL_DENIED)
                || ($item->provider_selected
                    && $item->provider_status === EmailConversationActionItem::PROVIDER_DENIED))->count();
            $stale = $items->filter(fn (EmailConversationActionItem $item): bool => ($item->personal_selected
                && $item->personal_status === EmailConversationActionItem::PERSONAL_STALE)
                || ($item->provider_selected
                    && $item->provider_status === EmailConversationActionItem::PROVIDER_STALE))->count();
            $failed = $items->filter(fn (EmailConversationActionItem $item): bool => ($item->personal_selected
                && $item->personal_status === EmailConversationActionItem::PERSONAL_FAILED)
                || ($item->provider_selected && in_array($item->provider_status, [
                    EmailConversationActionItem::PROVIDER_FAILED,
                    EmailConversationActionItem::PROVIDER_CONFLICTED,
                ], true)))->count();
            $actionable = $personalEffects
                ->where('personal_status', EmailConversationActionItem::PERSONAL_PENDING)->count()
                + $providerEffects->filter(fn (EmailConversationActionItem $item): bool => $item->provider_status === EmailConversationActionItem::PROVIDER_PENDING
                    && $item->email_remote_operation_id === null)->count();
            $successEvidence = $personalApplied + $providerSucceeded
                + $personalEffects
                    ->where('personal_status', EmailConversationActionItem::PERSONAL_UNCHANGED)->count()
                + $providerEffects
                    ->where('provider_status', EmailConversationActionItem::PROVIDER_UNCHANGED)->count();
            $effectCount = $personalEffects->count() + $providerEffects->count();

            $status = match (true) {
                $actionable > 0 => EmailConversationActionRun::STATUS_APPLYING,
                $staleEffects === $effectCount && $successEvidence === 0 => EmailConversationActionRun::STATUS_STALE,
                $failedEffects === $effectCount && $successEvidence === 0 => EmailConversationActionRun::STATUS_FAILED,
                $deniedEffects > 0 || $staleEffects > 0 || $failedEffects > 0 || $providerPending > 0 => EmailConversationActionRun::STATUS_PARTIAL,
                default => EmailConversationActionRun::STATUS_APPLIED,
            };

            $run->forceFill([
                'status' => $status,
                'personal_applied_count' => $personalApplied,
                'provider_pending_count' => $providerPending,
                'provider_succeeded_count' => $providerSucceeded,
                'denied_count' => $denied,
                'stale_count' => $stale,
                'failed_count' => $failed,
                'completed_at' => $status === EmailConversationActionRun::STATUS_APPLYING
                    || ($status === EmailConversationActionRun::STATUS_PARTIAL && $providerPending > 0)
                        ? null
                        : now(),
            ])->save();

            return $run->fresh();
        });
    }

    private function assertActorOwnsRun(EmailConversationActionRun $run, User $actor): void
    {
        if ((int) $run->requested_by !== (int) $actor->id
            || $run->operation !== EmailConversationActionRun::OPERATION_ACKNOWLEDGE) {
            throw new AuthorizationException('This mailbox action is not available.');
        }
    }
}
