<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserReadBaseline;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageUserState;
use App\Modules\Email\Models\EmailUnreadHandoverItem;
use App\Modules\Email\Models\EmailUnreadHandoverRun;
use App\Modules\Email\Services\EmailUnreadHandoverAuthorization;
use App\Modules\Email\Services\EmailUnreadHandoverFingerprint;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ApplyEmailUnreadHandover
{
    public function __construct(
        private readonly EmailUnreadHandoverAuthorization $authorization,
        private readonly EmailUnreadHandoverFingerprint $fingerprints,
    ) {}

    public function handle(User $actor, EmailUnreadHandoverRun $run): EmailUnreadHandoverRun
    {
        return DB::transaction(function () use ($actor, $run): EmailUnreadHandoverRun {
            $lockedRun = EmailUnreadHandoverRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($lockedRun->status === EmailUnreadHandoverRun::STATUS_APPLIED) {
                return $lockedRun->load('items');
            }

            if ($lockedRun->status !== EmailUnreadHandoverRun::STATUS_PREVIEWED) {
                return $lockedRun->load('items');
            }

            if ((int) $lockedRun->requested_by !== (int) $actor->id) {
                throw new AuthorizationException('Only the previewing actor may apply this unread handover.');
            }

            $account = EmailAccount::query()->lockForUpdate()->find($lockedRun->email_account_id);
            $lockedUsers = User::query()
                ->whereIn('id', [(int) $actor->id, (int) $lockedRun->target_user_id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $currentActor = $lockedUsers->get((int) $actor->id);
            $target = $lockedUsers->get((int) $lockedRun->target_user_id);

            if (! $account || ! $currentActor || ! $target) {
                return $this->markStale($lockedRun, 'authorization_target_missing');
            }

            $this->authorization->authorizeManager($currentActor, $account);

            try {
                $this->authorization->authorizeTarget($target, $account);
            } catch (AuthorizationException) {
                return $this->markStale($lockedRun, 'target_access_revoked');
            }

            $baseline = EmailAccountUserReadBaseline::query()
                ->where('email_account_id', $account->id)
                ->where('user_id', $target->id)
                ->lockForUpdate()
                ->first();
            $items = EmailUnreadHandoverItem::query()
                ->where('email_unread_handover_run_id', $lockedRun->id)
                ->orderBy('snapshot_order')
                ->lockForUpdate()
                ->get();

            if ($lockedRun->preview_expires_at->lessThanOrEqualTo(now())) {
                return $this->markStale($lockedRun, 'preview_expired', true, $items);
            }

            if (! $baseline
                || ! $baseline->ordinary_view_entitled
                || (int) $baseline->access_epoch !== (int) $lockedRun->access_epoch
                || (int) $baseline->baseline_message_id !== (int) $lockedRun->baseline_message_id
                || ! hash_equals(
                    $lockedRun->authorization_fingerprint,
                    $this->fingerprints->authorization($currentActor, $target, $account, $baseline),
                )) {
                return $this->markStale($lockedRun, 'authorization_or_epoch_changed', false, $items);
            }

            if ($items->count() !== (int) $lockedRun->selected_count
                || $items->contains(fn (EmailUnreadHandoverItem $item): bool => (int) $item->access_epoch !== (int) $baseline->access_epoch
                    || $item->status !== EmailUnreadHandoverItem::STATUS_PREVIEWED)) {
                return $this->markStale($lockedRun, 'snapshot_items_changed', false, $items);
            }

            $folderIds = collect($lockedRun->folder_scope_json)
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
            $currentSnapshot = $this->fingerprints->snapshot(
                $items,
                $folderIds,
                $lockedRun->date_from->format('Y-m-d H:i:s.u'),
                $lockedRun->date_to->format('Y-m-d H:i:s.u'),
                $baseline->access_epoch,
            );

            if (! hash_equals($lockedRun->snapshot_fingerprint, $currentSnapshot)
                || ! $this->snapshotRecordsRemainExact($lockedRun, $items, $folderIds)) {
                return $this->markStale($lockedRun, 'snapshot_records_changed', false, $items);
            }

            $now = now();
            $applied = 0;
            $alreadyUnread = 0;

            foreach ($items as $item) {
                $state = EmailMessageUserState::query()
                    ->where('email_message_id', $item->email_message_id)
                    ->where('user_id', $target->id)
                    ->where('access_epoch', $baseline->access_epoch)
                    ->lockForUpdate()
                    ->first();
                $wasUnread = $state
                    ? (bool) $state->is_unread
                    : (int) $item->email_message_id > (int) $baseline->baseline_message_id;

                $state ??= new EmailMessageUserState([
                    'email_message_id' => $item->email_message_id,
                    'user_id' => $target->id,
                    'access_epoch' => $baseline->access_epoch,
                    'opened_count' => 0,
                ]);
                $state->is_unread = true;
                $state->marked_unread_at = $now;
                $state->save();

                $item->forceFill([
                    'status' => $wasUnread
                        ? EmailUnreadHandoverItem::STATUS_ALREADY_UNREAD
                        : EmailUnreadHandoverItem::STATUS_APPLIED,
                    'applied_at' => $now,
                    'error_code' => null,
                ])->save();

                $wasUnread ? $alreadyUnread++ : $applied++;
            }

            $lockedRun->forceFill([
                'status' => EmailUnreadHandoverRun::STATUS_APPLIED,
                'applied_count' => $applied,
                'already_unread_count' => $alreadyUnread,
                'failed_count' => 0,
                'applied_at' => $now,
                'finished_at' => $now,
                'error_code' => null,
                'error_message' => null,
            ])->save();

            return $lockedRun->fresh()->load('items');
        });
    }

    /** @param  Collection<int, EmailUnreadHandoverItem>  $items */
    private function snapshotRecordsRemainExact(
        EmailUnreadHandoverRun $run,
        Collection $items,
        array $folderIds,
    ): bool {
        $currentFolderCount = EmailFolder::query()
            ->where('account_id', $run->email_account_id)
            ->whereIn('id', $folderIds)
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->lockForUpdate()
            ->count();

        if ($currentFolderCount !== count($folderIds)) {
            return false;
        }

        if ($items->isEmpty()) {
            return true;
        }

        $messages = EmailMessage::query()
            ->whereIn('id', $items->pluck('email_message_id'))
            ->lockForUpdate()
            ->get(['id', 'account_id', 'received_at'])
            ->keyBy('id');
        $placements = EmailMailboxPlacement::query()
            ->whereIn('id', $items->pluck('email_mailbox_placement_id'))
            ->lockForUpdate()
            ->get([
                'id',
                'email_message_id',
                'account_id',
                'email_folder_id',
                'local_state',
                'sync_status',
                'provider_deleted',
                'provider_missing_at',
            ])
            ->keyBy('id');

        foreach ($items as $item) {
            $message = $messages->get($item->email_message_id);
            $placement = $placements->get($item->email_mailbox_placement_id);

            if (! $message
                || ! $placement
                || (int) $message->account_id !== (int) $run->email_account_id
                || ! $message->received_at
                || $message->received_at->lt($run->date_from)
                || $message->received_at->gt($run->date_to)
                || (int) $placement->email_message_id !== (int) $item->email_message_id
                || (int) $placement->account_id !== (int) $run->email_account_id
                || (int) $placement->email_folder_id !== (int) $item->email_folder_id
                || ! in_array((int) $placement->email_folder_id, $folderIds, true)
                || $placement->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE
                || ! in_array($placement->sync_status, [
                    EmailMailboxPlacement::SYNC_SHADOW,
                    EmailMailboxPlacement::SYNC_SYNCED,
                ], true)
                || (bool) $placement->provider_deleted
                || $placement->provider_missing_at !== null) {
                return false;
            }
        }

        return true;
    }

    /** @param  Collection<int, EmailUnreadHandoverItem>|null  $items */
    private function markStale(
        EmailUnreadHandoverRun $run,
        string $errorCode,
        bool $expired = false,
        ?Collection $items = null,
    ): EmailUnreadHandoverRun {
        $now = now();
        $items ??= EmailUnreadHandoverItem::query()
            ->where('email_unread_handover_run_id', $run->id)
            ->lockForUpdate()
            ->get();

        foreach ($items as $item) {
            if ($item->status === EmailUnreadHandoverItem::STATUS_PREVIEWED) {
                $item->forceFill([
                    'status' => EmailUnreadHandoverItem::STATUS_STALE,
                    'error_code' => $errorCode,
                ])->save();
            }
        }

        $run->forceFill([
            'status' => $expired
                ? EmailUnreadHandoverRun::STATUS_EXPIRED
                : EmailUnreadHandoverRun::STATUS_STALE,
            'failed_count' => $items->count(),
            'finished_at' => $now,
            'error_code' => $errorCode,
            'error_message' => 'The unread handover preview is stale and no personal state was changed.',
        ])->save();

        return $run->fresh()->load('items');
    }
}
