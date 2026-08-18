<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMailboxDelegation;
use App\Modules\Email\Services\EmailMailboxAccessEventRecorder;
use App\Modules\Email\Services\EmailUnreadAccessEpochService;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\ResolveMailboxAccessDecision;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateEmailMailboxDelegation
{
    public function __construct(
        private readonly ResolveMailboxAccessDecision $decisions,
        private readonly EmailMailboxAccessEventRecorder $events,
        private readonly EmailUnreadAccessEpochService $unreadEpochs,
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
        User $delegate,
        array $input,
    ): EmailMailboxDelegation {
        $reason = $this->reason($input['reason'] ?? null);
        [$startsAt, $expiresAt] = $this->window($input);
        $operations = $this->operations($input);

        return DB::transaction(function () use (
            $account,
            $actor,
            $delegate,
            $reason,
            $startsAt,
            $expiresAt,
            $operations,
        ): EmailMailboxDelegation {
            $lockedAccount = EmailAccount::query()->lockForUpdate()->find($account->id);
            $currentActor = User::query()->find($actor->id);
            $currentDelegate = User::query()->find($delegate->id);

            if (! $lockedAccount?->is_active
                || ! $lockedAccount->isPersonal()
                || ! $currentActor?->isActive()
                || $currentActor->isSystemActor()
                || (int) $lockedAccount->owner_id !== (int) $currentActor->id) {
                throw new AuthorizationException('Only the active personal mailbox owner may create a delegation.');
            }

            if (! $currentDelegate?->isActive()
                || $currentDelegate->isSystemActor()
                || (int) $currentDelegate->id === (int) $currentActor->id) {
                throw ValidationException::withMessages([
                    'delegate_id' => 'Choose another active human user for this delegation.',
                ]);
            }

            $this->assertOwnerCanGrant($lockedAccount, $currentActor, $operations);
            $wasViewEntitled = $this->unreadEpochs->captureEntitlement(
                $lockedAccount,
                $currentDelegate,
            );

            $overlapExists = EmailMailboxDelegation::query()
                ->where('email_account_id', $lockedAccount->id)
                ->where('delegate_id', $currentDelegate->id)
                ->whereNull('revoked_at')
                ->where('starts_at', '<', $expiresAt)
                ->where('expires_at', '>', $startsAt)
                ->lockForUpdate()
                ->exists();

            if ($overlapExists) {
                throw ValidationException::withMessages([
                    'delegate_id' => 'This user already has a delegation during the selected time window.',
                ]);
            }

            $delegation = EmailMailboxDelegation::query()->create([
                'email_account_id' => $lockedAccount->id,
                'owner_id' => $currentActor->id,
                'delegate_id' => $currentDelegate->id,
                ...$operations,
                'reason' => $reason,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'created_by' => $currentActor->id,
            ]);

            $this->events->recordDelegationCreated($delegation);
            $this->unreadEpochs->reconcileAfterMutation(
                account: $lockedAccount,
                user: $currentDelegate,
                wasEntitled: $wasViewEntitled,
                source: EmailUnreadAccessEpochService::SOURCE_DELEGATION,
                sourceReference: 'delegation:'.$delegation->id,
                actor: $currentActor,
            );

            return $delegation->fresh(['account', 'owner', 'delegate']) ?? $delegation;
        }, 3);
    }

    /** @param  array<string, bool>  $operations */
    private function assertOwnerCanGrant(
        EmailAccount $account,
        User $owner,
        array $operations,
    ): void {
        $mapping = [
            'can_view' => MailboxAccess::VIEW,
            'can_organize' => MailboxAccess::ORGANIZE,
            'can_send' => MailboxAccess::SEND,
            'can_view_raw_source' => ResolveMailboxAccessDecision::RAW_SOURCE,
        ];

        foreach ($mapping as $field => $operation) {
            if ($operations[$field]
                && ! $this->decisions->resolve($owner, $account, $operation)->allowed) {
                throw ValidationException::withMessages([
                    'operations' => 'You cannot delegate a mailbox operation you do not currently hold.',
                ]);
            }
        }
    }

    /** @param  array<string, mixed>  $input
     * @return array<string, bool>
     */
    private function operations(array $input): array
    {
        $operations = [
            'can_view' => filter_var($input['can_view'] ?? false, FILTER_VALIDATE_BOOL),
            'can_organize' => filter_var($input['can_organize'] ?? false, FILTER_VALIDATE_BOOL),
            'can_send' => filter_var($input['can_send'] ?? false, FILTER_VALIDATE_BOOL),
            'can_view_raw_source' => filter_var($input['can_view_raw_source'] ?? false, FILTER_VALIDATE_BOOL),
        ];

        if (! in_array(true, $operations, true)) {
            throw ValidationException::withMessages([
                'operations' => 'Choose at least one mailbox operation.',
            ]);
        }

        if (($operations['can_organize'] || $operations['can_view_raw_source']) && ! $operations['can_view']) {
            throw ValidationException::withMessages([
                'operations' => 'Organize and raw-source access also require View.',
            ]);
        }

        return $operations;
    }

    /** @param  array<string, mixed>  $input
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function window(array $input): array
    {
        try {
            $startsAt = CarbonImmutable::parse((string) ($input['starts_at'] ?? ''));
            $expiresAt = CarbonImmutable::parse((string) ($input['expires_at'] ?? ''));
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'starts_at' => 'Enter a valid delegation start and expiry.',
            ]);
        }

        if ($startsAt->lessThan(now()->subMinute())) {
            throw ValidationException::withMessages([
                'starts_at' => 'A delegation cannot start in the past.',
            ]);
        }

        if ($expiresAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages([
                'expires_at' => 'Delegation expiry must be after its start.',
            ]);
        }

        if ($expiresAt->greaterThan($startsAt->addDays(EmailMailboxDelegation::MAX_DURATION_DAYS))) {
            throw ValidationException::withMessages([
                'expires_at' => 'A delegation cannot last longer than 31 days.',
            ]);
        }

        return [$startsAt->utc(), $expiresAt->utc()];
    }

    private function reason(mixed $value): string
    {
        $reason = trim((string) $value);

        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages([
                'reason' => 'Enter a delegation reason of no more than 2000 characters.',
            ]);
        }

        return $reason;
    }
}
