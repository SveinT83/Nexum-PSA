<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageUserState;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use Illuminate\Support\Facades\DB;

final class EmailProviderAbsenceProjector
{
    public function __construct(
        private readonly EmailConversationProjector $conversations,
        private readonly EmailProviderMessageIdentity $identities,
    ) {}

    public function confirmMissing(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationItem $item,
        string $terminalStatus = EmailProviderReconciliationItem::STATUS_CONFIRMED_MISSING,
    ): bool {
        if (! in_array($terminalStatus, [
            EmailProviderReconciliationItem::STATUS_CONFIRMED_MISSING,
            EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE,
        ], true)) {
            return false;
        }

        return DB::transaction(function () use ($item, $run, $terminalStatus): bool {
            $lockedRun = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($run->id);
            $folder = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->find($item->email_provider_reconciliation_folder_id);
            $lockedItem = EmailProviderReconciliationItem::query()
                ->lockForUpdate()
                ->find($item->id);
            if (! $this->activeProjectionScope($lockedRun, $folder, $lockedItem)) {
                return false;
            }
            if ($lockedItem->terminal()) {
                return false;
            }

            $targetPlacementId = $terminalStatus === EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE
                ? (int) $lockedItem->target_placement_id
                : 0;
            $placementIds = collect([
                (int) $lockedItem->source_placement_id,
                $targetPlacementId,
            ])->filter()->unique()->sort()->values()->all();
            $placements = EmailMailboxPlacement::query()
                ->whereIn('id', $placementIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $placement = $placements->get((int) $lockedItem->source_placement_id);

            if (! $placement
                || $placement->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE
                || (int) $placement->sync_version !== (int) $lockedItem->placement_sync_version_before) {
                $lockedItem->forceFill([
                    'status' => EmailProviderReconciliationItem::STATUS_STALE,
                    'error_code' => 'placement_changed_before_absence',
                    'completed_at' => now(),
                ])->save();
                $lockedRun->markAutomationScopeUnsafe();

                return false;
            }

            if ($terminalStatus === EmailProviderReconciliationItem::STATUS_CONFIRMED_MISSING
                && is_string($lockedItem->identity_hash)) {
                $sourceMessage = EmailMessage::query()
                    ->withTrashed()
                    ->whereKey($placement->email_message_id)
                    ->lockForUpdate()
                    ->first();
                $currentIdentity = $sourceMessage
                    ? $this->identities->forMessage($sourceMessage)
                    : null;
                if (! is_string($currentIdentity)
                    || ! hash_equals($lockedItem->identity_hash, $currentIdentity)) {
                    $lockedItem->forceFill([
                        'status' => EmailProviderReconciliationItem::STATUS_CONFLICT,
                        'error_code' => 'provider_absence_source_identity_drift',
                        'completed_at' => now(),
                    ])->save();
                    $lockedRun->markAutomationScopeUnsafe();

                    return false;
                }
            }

            if ($terminalStatus === EmailProviderReconciliationItem::STATUS_CONFIRMED_MOVE) {
                $target = $placements->get($targetPlacementId);
                if (! $target || ! $this->preserveConfirmedMovePersonalState(
                    $lockedItem,
                    $placement,
                    $target,
                )) {
                    $lockedItem->forceFill([
                        'status' => EmailProviderReconciliationItem::STATUS_CONFLICT,
                        'error_code' => 'provider_move_personal_state_conflict',
                        'completed_at' => now(),
                    ])->save();
                    $lockedRun->markAutomationScopeUnsafe();

                    return false;
                }
            }

            $nextVersion = max(1, (int) $placement->sync_version) + 1;
            $placement->forceFill([
                'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
                'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
                'sync_version' => $nextVersion,
                'provider_missing_at' => now(),
                'last_reconciled_at' => now(),
                'sync_error_code' => null,
                'sync_error_message' => null,
            ])->save();
            $lockedItem->forceFill([
                'status' => $terminalStatus,
                'placement_sync_version_after' => $nextVersion,
                'completed_at' => now(),
                'error_code' => null,
            ])->save();

            $hasActivePlacement = EmailMailboxPlacement::query()
                ->where('email_message_id', $placement->email_message_id)
                ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                ->exists();
            if (! $hasActivePlacement && $placement->message && ! $placement->message->trashed()) {
                $placement->message->delete();
            }

            if ($placement->email_conversation_id) {
                $this->conversations->refreshConversation(
                    EmailConversation::query()->find($placement->email_conversation_id),
                );
            }

            return true;
        }, 3);
    }

    private function activeProjectionScope(
        ?EmailProviderReconciliationRun $run,
        ?EmailProviderReconciliationFolder $folder,
        ?EmailProviderReconciliationItem $item,
    ): bool {
        if (! $run || ! $folder || ! $item
            || $run->status !== EmailProviderReconciliationRun::STATUS_RUNNING
            || $run->phase !== EmailProviderReconciliationRun::PHASE_DISCOVER_END
            || (int) $run->active_slot !== 1
            || $run->cancellation_requested_at !== null
            || $run->final_summary_status !== null
            || (int) $folder->email_provider_reconciliation_run_id !== (int) $run->id
            || (int) $item->email_provider_reconciliation_run_id !== (int) $run->id
            || (int) $item->email_provider_reconciliation_folder_id !== (int) $folder->id) {
            return false;
        }

        if ($folder->item_summary_status !== null) {
            return false;
        }

        return ($folder->status === EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS
                && $folder->reason_code === 'stable_absence_projection')
            || ($folder->status === EmailProviderReconciliationFolder::STATUS_PENDING
                && $folder->discovery_state
                    === EmailProviderReconciliationFolder::DISCOVERY_LOCAL_ONLY
                && $folder->reason_code === 'stable_folder_absence_projection');
    }

    /**
     * Rebind personal state only for an exact, stable, same-account provider
     * move. The state update is set based and shares the transaction that
     * hides the source occurrence, so a collision leaves both messages and
     * every user/epoch row unchanged.
     */
    private function preserveConfirmedMovePersonalState(
        EmailProviderReconciliationItem $item,
        EmailMailboxPlacement $source,
        EmailMailboxPlacement $target,
    ): bool {
        if ((int) $target->id === (int) $source->id
            || $target->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE
            || $target->provider_missing_at !== null
            || (int) $target->account_id !== (int) $source->account_id
            || (int) $target->last_provider_reconciliation_run_id
                !== (int) $item->email_provider_reconciliation_run_id) {
            return false;
        }

        $run = EmailProviderReconciliationRun::query()
            ->whereKey($item->email_provider_reconciliation_run_id)
            ->where('account_id', $source->account_id)
            ->first();
        if (! $run) {
            return false;
        }

        $folder = EmailFolder::query()
            ->whereKey($target->email_folder_id)
            ->where('account_id', $source->account_id)
            ->where('active_uid_namespace_id', $target->uid_namespace_id)
            ->where('path', $target->folder_path)
            ->first();
        $namespaceCurrent = EmailFolderUidNamespace::query()
            ->whereKey($target->uid_namespace_id)
            ->where('account_id', $source->account_id)
            ->where('email_folder_id', $target->email_folder_id)
            ->where('uid_validity', $target->imap_uid_validity)
            ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
            ->exists();
        $folderRunStable = EmailProviderReconciliationFolder::query()
            ->where('email_provider_reconciliation_run_id', $item->email_provider_reconciliation_run_id)
            ->where('account_id', $source->account_id)
            ->where('email_folder_id', $target->email_folder_id)
            ->where('uid_namespace_id', $target->uid_namespace_id)
            ->where('folder_path', $target->folder_path)
            ->where('expected_uid_validity', $target->imap_uid_validity)
            ->where('start_uid_validity', $target->imap_uid_validity)
            ->where('end_uid_validity', $target->imap_uid_validity)
            ->where(function ($query): void {
                $query->where('status', EmailProviderReconciliationFolder::STATUS_COMPLETE)
                    ->orWhere(function ($stable): void {
                        $stable->where(
                            'status',
                            EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS,
                        )->whereIn(
                            'reason_code',
                            EmailProviderReconciliationFolder::STABLE_EVIDENCE_REASON_CODES,
                        );
                    });
            })
            ->exists();
        $targetEvidenceExact = EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $item->email_provider_reconciliation_run_id)
            ->where('uid_namespace_id', $target->uid_namespace_id)
            ->where('imap_uid', $target->imap_uid)
            ->where('placement_sync_version_after', $target->sync_version)
            ->where(function ($evidence) use ($target): void {
                $evidence->where('source_placement_id', $target->id)
                    ->orWhere('result_placement_id', $target->id);
            })
            ->exists();
        if (! $folder || ! $namespaceCurrent || ! $folderRunStable || ! $targetEvidenceExact) {
            return false;
        }

        $messageIds = collect([
            (int) $source->email_message_id,
            (int) $target->email_message_id,
        ])->unique()->sort()->values()->all();
        $messages = EmailMessage::query()
            ->withTrashed()
            ->whereIn('id', $messageIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $sourceMessage = $messages->get((int) $source->email_message_id);
        $targetMessage = $messages->get((int) $target->email_message_id);
        $sourceIdentity = $sourceMessage ? $this->identities->forMessage($sourceMessage) : null;
        $targetIdentity = $targetMessage ? $this->identities->forMessage($targetMessage) : null;
        if (! $sourceMessage || ! $targetMessage || $targetMessage->trashed()
            || (int) $sourceMessage->account_id !== (int) $run->account_id
            || (int) $targetMessage->account_id !== (int) $run->account_id
            || ((int) $sourceMessage->id !== (int) $targetMessage->id
                && ((string) $targetMessage->mailbox !== (string) $target->folder_path
                    || (int) $targetMessage->imap_uid_validity !== (int) $target->imap_uid_validity
                    || (int) $targetMessage->imap_uid !== (int) $target->imap_uid))
            || ! is_string($item->identity_hash)
            || ! is_string($sourceIdentity)
            || ! is_string($targetIdentity)
            || ! hash_equals($item->identity_hash, $sourceIdentity)
            || ! hash_equals($item->identity_hash, $targetIdentity)) {
            return false;
        }

        $sourceMessageId = (int) $sourceMessage->id;
        $targetMessageId = (int) $targetMessage->id;
        if ($sourceMessageId !== $targetMessageId) {
            // The deterministically locked source/target EmailMessage rows
            // serialize the normal Open/Unread writers. The self-join asks
            // only whether one unique user+epoch key would collide, without
            // materializing an unbounded mailbox audience in this worker.
            $hasCollision = DB::table('email_message_user_states as source_states')
                ->join('email_message_user_states as target_states', function ($join): void {
                    $join->on('target_states.user_id', '=', 'source_states.user_id')
                        ->on('target_states.access_epoch', '=', 'source_states.access_epoch');
                })
                ->where('source_states.email_message_id', $sourceMessageId)
                ->where('target_states.email_message_id', $targetMessageId)
                ->exists();
            if ($hasCollision) {
                return false;
            }
        }

        $now = now();
        $stateUpdate = [
            'last_opened_placement_id' => DB::raw(
                'case when last_opened_placement_id = '.(int) $source->id
                .' then '.(int) $target->id.' else last_opened_placement_id end',
            ),
            'updated_at' => $now,
        ];
        if ($sourceMessageId !== $targetMessageId) {
            $stateUpdate['email_message_id'] = $targetMessageId;
        }

        EmailMessageUserState::query()
            ->where('email_message_id', $sourceMessageId)
            ->when(
                $sourceMessageId === $targetMessageId,
                fn ($query) => $query->where('last_opened_placement_id', $source->id),
            )
            ->update($stateUpdate);

        return true;
    }

    public function restoreObserved(EmailMailboxPlacement $placement): EmailMailboxPlacement
    {
        $conversationId = $placement->email_conversation_id;
        $placement->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => max(1, (int) $placement->sync_version) + 1,
            'provider_missing_at' => null,
            'last_reconciled_at' => now(),
            'sync_error_code' => null,
            'sync_error_message' => null,
        ])->save();

        if ($placement->message?->trashed()) {
            $placement->message->restore();
        }

        if ($conversationId) {
            $this->conversations->refreshConversation(
                EmailConversation::query()->find($conversationId),
            );
        }

        return $placement->refresh();
    }
}
