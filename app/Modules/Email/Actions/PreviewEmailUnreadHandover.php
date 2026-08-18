<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailUnreadHandoverItem;
use App\Modules\Email\Models\EmailUnreadHandoverRun;
use App\Modules\Email\Services\EmailUnreadAccessEpochService;
use App\Modules\Email\Services\EmailUnreadHandoverAuthorization;
use App\Modules\Email\Services\EmailUnreadHandoverFingerprint;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class PreviewEmailUnreadHandover
{
    public function __construct(
        private readonly EmailUnreadAccessEpochService $epochs,
        private readonly EmailUnreadHandoverAuthorization $authorization,
        private readonly EmailUnreadHandoverFingerprint $fingerprints,
    ) {}

    /**
     * Preview returns metadata-only run/items. Callers must not hydrate message
     * relationships when the manager lacks independent mailbox View access.
     *
     * @param  array<int, int|string>  $folderIds
     */
    public function handle(
        User $actor,
        EmailAccount $account,
        User $target,
        array $folderIds,
        CarbonInterface $dateFrom,
        CarbonInterface $dateTo,
        string $reason,
        int $maximum = EmailUnreadHandoverRun::DEFAULT_CAP,
        ?string $idempotencyKey = null,
    ): EmailUnreadHandoverRun {
        $dateFrom = CarbonImmutable::instance($dateFrom)->setMicrosecond(0);
        $dateTo = CarbonImmutable::instance($dateTo)->setMicrosecond(0);
        $folderIds = collect($folderIds)
            ->map(fn (mixed $id): int => filter_var($id, FILTER_VALIDATE_INT) ?: 0)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $reason = trim($reason);

        if ($folderIds === []) {
            throw new InvalidArgumentException('Choose at least one exact mailbox folder.');
        }

        if ($dateFrom->greaterThan($dateTo)) {
            throw new InvalidArgumentException('The unread handover date range is invalid.');
        }

        if ($maximum < 1 || $maximum > EmailUnreadHandoverRun::MAX_CAP) {
            throw new InvalidArgumentException('Unread handover count must be between 1 and 500.');
        }

        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw new InvalidArgumentException('A concise unread handover reason is required.');
        }

        $idempotencyKey = hash('sha256', $idempotencyKey ?? (string) Str::uuid());
        $existing = EmailUnreadHandoverRun::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            $currentAccount = EmailAccount::query()->findOrFail($account->id);
            $currentActor = User::query()->findOrFail($actor->id);
            $currentTarget = User::query()->findOrFail($target->id);

            return $this->reuseExisting(
                $existing,
                $currentActor,
                $currentAccount,
                $currentTarget,
                $folderIds,
                $dateFrom,
                $dateTo,
                $maximum,
                $reason,
            );
        }

        try {
            return DB::transaction(function () use (
                $account,
                $actor,
                $dateFrom,
                $dateTo,
                $folderIds,
                $idempotencyKey,
                $maximum,
                $reason,
                $target,
            ): EmailUnreadHandoverRun {
                $lockedAccount = EmailAccount::query()->lockForUpdate()->findOrFail($account->id);
                $lockedUsers = User::query()
                    ->whereIn('id', [(int) $actor->id, (int) $target->id])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $lockedActor = $lockedUsers->get((int) $actor->id);
                $lockedTarget = $lockedUsers->get((int) $target->id);

                if (! $lockedActor || ! $lockedTarget) {
                    throw new LogicException('The unread handover actor or target no longer exists.');
                }

                $this->authorization->authorizeManager($lockedActor, $lockedAccount);
                $this->authorization->authorizeTarget($lockedTarget, $lockedAccount);
                $serializedExisting = EmailUnreadHandoverRun::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($serializedExisting) {
                    return $this->reuseExisting(
                        $serializedExisting,
                        $lockedActor,
                        $lockedAccount,
                        $lockedTarget,
                        $folderIds,
                        $dateFrom,
                        $dateTo,
                        $maximum,
                        $reason,
                    );
                }

                $validFolders = EmailFolder::query()
                    ->where('account_id', $lockedAccount->id)
                    ->whereIn('id', $folderIds)
                    ->where('is_selectable', true)
                    ->where('sync_enabled', true)
                    ->lockForUpdate()
                    ->get(['id']);

                if ($validFolders->count() !== count($folderIds)) {
                    throw new InvalidArgumentException(
                        'Every selected folder must be current, selectable, synchronized, and belong to the exact mailbox.',
                    );
                }

                $baseline = $this->epochs->ensureCurrentEntitlement($lockedAccount, $lockedTarget, $lockedActor);

                if (! $baseline) {
                    throw new LogicException('The target has no current unread access epoch.');
                }

                $messages = EmailMessage::query()
                    ->where('account_id', $lockedAccount->id)
                    ->whereBetween('received_at', [$dateFrom, $dateTo])
                    ->whereHas('placements', function ($placements) use ($folderIds): void {
                        $placements
                            ->whereIn('email_folder_id', $folderIds)
                            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                            ->where('provider_deleted', false)
                            ->whereNull('provider_missing_at')
                            ->whereIn('sync_status', [
                                EmailMailboxPlacement::SYNC_SHADOW,
                                EmailMailboxPlacement::SYNC_SYNCED,
                            ]);
                    })
                    ->with(['placements' => function ($placements) use ($folderIds): void {
                        $placements
                            ->whereIn('email_folder_id', $folderIds)
                            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                            ->where('provider_deleted', false)
                            ->whereNull('provider_missing_at')
                            ->whereIn('sync_status', [
                                EmailMailboxPlacement::SYNC_SHADOW,
                                EmailMailboxPlacement::SYNC_SYNCED,
                            ])
                            ->orderBy('id');
                    }])
                    ->orderByDesc('received_at')
                    ->orderByDesc('id')
                    ->limit($maximum)
                    ->get(['id', 'account_id', 'received_at']);

                $itemRows = $messages
                    ->values()
                    ->map(function (EmailMessage $message, int $index) use ($baseline): array {
                        /** @var EmailMailboxPlacement|null $placement */
                        $placement = $message->placements->first();

                        if (! $placement) {
                            throw new LogicException('The unread handover placement snapshot changed during preview.');
                        }

                        return [
                            'snapshot_order' => $index + 1,
                            'email_message_id' => (int) $message->id,
                            'email_mailbox_placement_id' => (int) $placement->id,
                            'email_folder_id' => (int) $placement->email_folder_id,
                            'access_epoch' => (int) $baseline->access_epoch,
                        ];
                    })
                    ->all();
                $dateFromValue = $dateFrom->format('Y-m-d H:i:s.u');
                $dateToValue = $dateTo->format('Y-m-d H:i:s.u');
                $authorizationFingerprint = $this->fingerprints->authorization(
                    $lockedActor,
                    $lockedTarget,
                    $lockedAccount,
                    $baseline,
                );
                $snapshotFingerprint = $this->fingerprints->snapshot(
                    $itemRows,
                    $folderIds,
                    $dateFromValue,
                    $dateToValue,
                    $baseline->access_epoch,
                );
                $now = now();
                $run = EmailUnreadHandoverRun::query()->create([
                    'email_account_id' => $lockedAccount->id,
                    'requested_by' => $lockedActor->id,
                    'target_user_id' => $lockedTarget->id,
                    'status' => EmailUnreadHandoverRun::STATUS_PREVIEWED,
                    'reason' => $reason,
                    'folder_scope_json' => $folderIds,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'requested_cap' => $maximum,
                    'access_epoch' => $baseline->access_epoch,
                    'baseline_message_id' => $baseline->baseline_message_id,
                    'authorization_fingerprint' => $authorizationFingerprint,
                    'snapshot_fingerprint' => $snapshotFingerprint,
                    'idempotency_key' => $idempotencyKey,
                    'selected_count' => count($itemRows),
                    'preview_expires_at' => $now->copy()->addMinutes(EmailUnreadHandoverRun::PREVIEW_TTL_MINUTES),
                ]);

                foreach ($itemRows as $itemRow) {
                    $run->items()->create($itemRow + [
                        'status' => EmailUnreadHandoverItem::STATUS_PREVIEWED,
                    ]);
                }

                return $run->fresh()->load('items');
            });
        } catch (QueryException $exception) {
            if (! $this->isIdempotencyDuplicate($exception)) {
                throw $exception;
            }

            $winner = EmailUnreadHandoverRun::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (! $winner) {
                throw $exception;
            }

            return $this->reuseExisting(
                $winner,
                User::query()->findOrFail($actor->id),
                EmailAccount::query()->findOrFail($account->id),
                User::query()->findOrFail($target->id),
                $folderIds,
                $dateFrom,
                $dateTo,
                $maximum,
                $reason,
            );
        }
    }

    /** @param  array<int, int>  $folderIds */
    private function reuseExisting(
        EmailUnreadHandoverRun $existing,
        User $actor,
        EmailAccount $account,
        User $target,
        array $folderIds,
        CarbonInterface $dateFrom,
        CarbonInterface $dateTo,
        int $maximum,
        string $reason,
    ): EmailUnreadHandoverRun {
        $this->authorization->authorizeManager($actor, $account);
        $this->authorization->authorizeTarget($target, $account);
        $existingFolderIds = collect($existing->folder_scope_json)
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        if ((int) $existing->requested_by !== (int) $actor->id
            || (int) $existing->email_account_id !== (int) $account->id
            || (int) $existing->target_user_id !== (int) $target->id
            || $existingFolderIds !== $folderIds
            || ! $existing->date_from->equalTo($dateFrom)
            || ! $existing->date_to->equalTo($dateTo)
            || (int) $existing->requested_cap !== $maximum
            || ! hash_equals((string) $existing->reason, $reason)) {
            throw new LogicException('The unread handover idempotency key belongs to another scope.');
        }

        return $existing->load('items');
    }

    private function isIdempotencyDuplicate(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $message = mb_strtolower($exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains($message, 'email_unread_handover_runs')
            && str_contains($message, 'idempotency_key');
    }
}
