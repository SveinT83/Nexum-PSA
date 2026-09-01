<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailBreakGlassAccess;
use App\Modules\Email\Services\EmailLiveAuthorityCoordinator;
use App\Modules\Email\Services\EmailMailboxAccessEventRecorder;
use App\Modules\Notification\Actions\ScheduleMailboxBreakGlassActivationNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivateEmailBreakGlassAccess
{
    public function __construct(
        private readonly EmailMailboxAccessEventRecorder $events,
        private readonly ScheduleMailboxBreakGlassActivationNotification $notifications,
        private readonly EmailLiveAuthorityCoordinator $liveAuthority,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(
        EmailAccount $account,
        User $actor,
        array $input,
    ): EmailBreakGlassAccess {
        $reason = $this->reason($input['reason'] ?? null);
        $duration = $this->duration($input['duration_minutes'] ?? null);
        $operations = $this->operations($input);
        $confirmation = trim((string) ($input['account_confirmation'] ?? ''));

        return DB::transaction(function () use (
            $account,
            $actor,
            $reason,
            $duration,
            $operations,
            $confirmation,
        ): EmailBreakGlassAccess {
            $lockedAccount = EmailAccount::query()->lockForUpdate()->find($account->id);
            $currentActor = User::query()->find($actor->id);
            $currentOwner = $lockedAccount?->owner_id
                ? User::query()->find($lockedAccount->owner_id)
                : null;

            if (! $lockedAccount?->is_active
                || ! $lockedAccount->isPersonal()
                || ! $currentOwner
                || $currentOwner->isSystemActor()
                || ! $currentActor?->isActive()
                || $currentActor->isSystemActor()
                || ! $currentActor->can('email.break_glass_activate')) {
                throw new AuthorizationException('Emergency mailbox access is not available.');
            }

            if ((int) $lockedAccount->owner_id === (int) $currentActor->id) {
                throw ValidationException::withMessages([
                    'account_confirmation' => 'Use your ordinary owner access for this mailbox.',
                ]);
            }

            if (! hash_equals(
                mb_strtolower(trim((string) $lockedAccount->address)),
                mb_strtolower($confirmation),
            )) {
                throw ValidationException::withMessages([
                    'account_confirmation' => 'The mailbox confirmation does not match.',
                ]);
            }

            if ($operations['can_view_raw_source'] && ! $currentActor->can('email.raw_source_view')) {
                throw ValidationException::withMessages([
                    'can_view_raw_source' => 'Raw-source access requires the separate raw-source permission.',
                ]);
            }

            $overlapExists = EmailBreakGlassAccess::query()
                ->where('email_account_id', $lockedAccount->id)
                ->where('actor_id', $currentActor->id)
                ->effective()
                ->lockForUpdate()
                ->exists();

            if ($overlapExists) {
                throw ValidationException::withMessages([
                    'account_confirmation' => 'You already have active emergency access to this mailbox.',
                ]);
            }

            $generation = $this->liveAuthority->prepareAccountMutation(
                $lockedAccount,
                [$currentActor->id],
            );
            $startsAt = now()->utc();
            $access = EmailBreakGlassAccess::query()->forceCreate([
                'email_account_id' => $lockedAccount->id,
                'actor_id' => $currentActor->id,
                ...$operations,
                'reason' => $reason,
                'starts_at' => $startsAt,
                'expires_at' => $startsAt->copy()->addMinutes($duration),
                'email_live_enable_generation' => $generation,
                'email_live_start_invalidated_at' => now(),
            ]);

            $access->setRelation('account', $lockedAccount);
            $this->events->recordBreakGlassActivated($access);
            $this->notifications->schedule($access);

            return $access->fresh(['account', 'actor']) ?? $access;
        }, 3);
    }

    /** @param  array<string, mixed>  $input
     * @return array<string, bool>
     */
    private function operations(array $input): array
    {
        $operations = [
            'can_view_content' => filter_var($input['can_view_content'] ?? false, FILTER_VALIDATE_BOOL),
            'can_search' => filter_var($input['can_search'] ?? false, FILTER_VALIDATE_BOOL),
            'can_download_attachments' => filter_var($input['can_download_attachments'] ?? false, FILTER_VALIDATE_BOOL),
            'can_view_raw_source' => filter_var($input['can_view_raw_source'] ?? false, FILTER_VALIDATE_BOOL),
        ];

        if (! in_array(true, $operations, true)) {
            throw ValidationException::withMessages([
                'operations' => 'Choose at least one emergency content operation.',
            ]);
        }

        return $operations;
    }

    private function duration(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw ValidationException::withMessages([
                'duration_minutes' => 'Choose a whole-number emergency duration.',
            ]);
        }

        $duration = (int) $value;
        if ($duration < 1 || $duration > EmailBreakGlassAccess::MAX_DURATION_MINUTES) {
            throw ValidationException::withMessages([
                'duration_minutes' => 'Emergency access must expire within 120 minutes.',
            ]);
        }

        return $duration;
    }

    private function reason(mixed $value): string
    {
        $reason = trim((string) $value);

        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages([
                'reason' => 'Enter an emergency reason of no more than 2000 characters.',
            ]);
        }

        return $reason;
    }
}
