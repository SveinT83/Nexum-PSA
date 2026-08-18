<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Jobs\ProcessEmailCanonicalCorrelationRun;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailCanonicalCorrelationRun;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailCanonicalCorrelationEvidence;
use App\Modules\Email\Services\EmailCanonicalCorrelationScope;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\ResolveMailboxAccessDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartEmailCanonicalCorrelationRun
{
    public const DEFAULT_MESSAGE_CAP = 2000;

    public const HARD_MESSAGE_CAP = 5000;

    public const DEFAULT_GROUP_CAP = 250;

    public const HARD_GROUP_CAP = 500;

    public const DEFAULT_PAIR_CAP = 2500;

    public const HARD_PAIR_CAP = 5000;

    public const DEFAULT_PER_GROUP_CAP = 20;

    public const HARD_PER_GROUP_CAP = 50;

    public function __construct(
        private readonly ResolveMailboxAccessDecision $accessDecisions,
        private readonly EmailCanonicalCorrelationScope $scope,
    ) {}

    /**
     * @param  list<int|string>  $accountIds
     * @param  array<string, mixed>  $options
     */
    public function handle(User $actor, array $accountIds, array $options = []): EmailCanonicalCorrelationRun
    {
        $actor = User::query()->find($actor->id);
        if (! $actor?->isActive() || $actor->isSystemActor() || ! $actor->can('email.mailbox_sync_manage')) {
            throw new AuthorizationException('Canonical correlation maintenance is not available.');
        }

        $accountIds = collect($accountIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($accountIds === [] || count($accountIds) > 25) {
            throw ValidationException::withMessages([
                'account_ids' => 'Choose between one and 25 exact mail accounts.',
            ]);
        }

        $accounts = EmailAccount::query()
            ->whereIn('id', $accountIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        if ($accounts->count() !== count($accountIds)) {
            throw new AuthorizationException('One or more mail accounts are unavailable.');
        }

        foreach ($accountIds as $accountId) {
            if (! $this->accessDecisions->resolve($actor, $accounts->get($accountId), MailboxAccess::VIEW)->allowed) {
                throw new AuthorizationException('One or more mail accounts are unavailable.');
            }
        }

        $caps = $this->caps($options);
        $minimumMessageId = $this->messageBoundary($options['min_message_id'] ?? 1, 'min_message_id');
        $currentMaximumMessageId = (int) EmailMessage::query()
            ->whereIn('account_id', $accountIds)
            ->max('id');
        $requestedMaximumMessageId = array_key_exists('max_message_id', $options)
            ? $this->messageBoundary($options['max_message_id'], 'max_message_id')
            : $currentMaximumMessageId;
        $maxMessageId = min($requestedMaximumMessageId, $currentMaximumMessageId);

        if ($maxMessageId < $minimumMessageId) {
            throw ValidationException::withMessages([
                'max_message_id' => 'The maximum message ID must include a current message at or above the minimum.',
            ]);
        }

        $messageCount = EmailMessage::query()
            ->whereIn('account_id', $accountIds)
            ->whereBetween('id', [$minimumMessageId, $maxMessageId])
            ->orderBy('id')
            ->limit($caps['message_cap'] + 1)
            ->pluck('id')
            ->count();

        if ($messageCount > $caps['message_cap']) {
            throw ValidationException::withMessages([
                'message_cap' => 'The frozen scope exceeds the selected message cap. Narrow the account or message-ID scope.',
            ]);
        }

        if ($messageCount === 0) {
            throw ValidationException::withMessages([
                'account_ids' => 'The selected account and message-ID scope contains no current messages.',
            ]);
        }

        $snapshot = $this->scope->snapshot(
            $accountIds,
            $minimumMessageId,
            $maxMessageId,
            $caps['evidence_snapshot_byte_cap'],
        );
        if ($snapshot['exceeded']) {
            throw ValidationException::withMessages([
                'message_cap' => 'The local evidence snapshot exceeds the safe byte budget. Narrow the account or message-ID scope.',
            ]);
        }
        if ($snapshot['count'] !== $messageCount) {
            throw ValidationException::withMessages([
                'account_ids' => 'The bounded local message scope changed while it was being frozen. Try again.',
            ]);
        }
        $scopeFingerprint = $this->scope->fingerprint(
            $accountIds,
            $minimumMessageId,
            $maxMessageId,
            $caps,
            $snapshot['message_digest'],
        );
        $idempotencyKey = hash('sha256', implode(':', [
            'email-canonical-correlation',
            $actor->id,
            $scopeFingerprint,
        ]));

        $run = DB::transaction(function () use (
            $actor,
            $accountIds,
            $minimumMessageId,
            $maxMessageId,
            $messageCount,
            $scopeFingerprint,
            $idempotencyKey,
            $caps,
            $snapshot,
        ): EmailCanonicalCorrelationRun {
            return EmailCanonicalCorrelationRun::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'requested_by' => $actor->id,
                    'algorithm_version' => EmailCanonicalCorrelationEvidence::ALGORITHM_VERSION,
                    'status' => EmailCanonicalCorrelationRun::STATUS_QUEUED,
                    'account_scope_json' => $accountIds,
                    'frozen_min_message_id' => $minimumMessageId,
                    'frozen_max_message_id' => $maxMessageId,
                    'message_cap' => $caps['message_cap'],
                    'group_cap' => $caps['group_cap'],
                    'pair_cap' => $caps['pair_cap'],
                    'per_group_cap' => $caps['per_group_cap'],
                    'evidence_snapshot_byte_cap' => $caps['evidence_snapshot_byte_cap'],
                    'evidence_run_byte_cap' => $caps['evidence_run_byte_cap'],
                    'scoped_evidence_bytes' => $snapshot['evidence_bytes'],
                    'evidence_bytes_processed' => $snapshot['evidence_bytes'],
                    'scope_fingerprint' => $scopeFingerprint,
                    'scoped_message_count' => $messageCount,
                ],
            );
        }, 3);

        if (in_array($run->status, [
            EmailCanonicalCorrelationRun::STATUS_QUEUED,
            EmailCanonicalCorrelationRun::STATUS_RUNNING,
        ], true)) {
            ProcessEmailCanonicalCorrelationRun::dispatch((int) $run->id)
                ->onQueue('email')
                ->afterCommit();
        }

        return $run;
    }

    /** @param  array<string, mixed>  $options
     * @return array{message_cap:int,group_cap:int,pair_cap:int,per_group_cap:int,evidence_snapshot_byte_cap:int,evidence_run_byte_cap:int}
     */
    private function caps(array $options): array
    {
        $snapshotByteCap = $this->cap(
            $options['evidence_snapshot_byte_cap'] ?? EmailCanonicalCorrelationScope::SNAPSHOT_BYTE_CAP,
            EmailCanonicalCorrelationScope::SNAPSHOT_BYTE_CAP,
            'evidence_snapshot_byte_cap',
        );
        $runByteCap = $this->cap(
            $options['evidence_run_byte_cap'] ?? EmailCanonicalCorrelationScope::RUN_BYTE_CAP,
            EmailCanonicalCorrelationScope::RUN_BYTE_CAP,
            'evidence_run_byte_cap',
        );
        if ($runByteCap < $snapshotByteCap * 2) {
            throw ValidationException::withMessages([
                'evidence_run_byte_cap' => 'The run evidence budget must cover the initial and final frozen snapshots.',
            ]);
        }

        return [
            'message_cap' => $this->cap($options['message_cap'] ?? self::DEFAULT_MESSAGE_CAP, self::HARD_MESSAGE_CAP, 'message_cap'),
            'group_cap' => $this->cap($options['group_cap'] ?? self::DEFAULT_GROUP_CAP, self::HARD_GROUP_CAP, 'group_cap'),
            'pair_cap' => $this->cap($options['pair_cap'] ?? self::DEFAULT_PAIR_CAP, self::HARD_PAIR_CAP, 'pair_cap'),
            'per_group_cap' => $this->cap($options['per_group_cap'] ?? self::DEFAULT_PER_GROUP_CAP, self::HARD_PER_GROUP_CAP, 'per_group_cap'),
            'evidence_snapshot_byte_cap' => $snapshotByteCap,
            'evidence_run_byte_cap' => $runByteCap,
        ];
    }

    private function cap(mixed $value, int $maximum, string $field): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false || $validated < 1 || $validated > $maximum) {
            throw ValidationException::withMessages([
                $field => "Choose a {$field} between 1 and {$maximum}.",
            ]);
        }

        return (int) $validated;
    }

    private function messageBoundary(mixed $value, string $field): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false || $validated < 1) {
            throw ValidationException::withMessages([
                $field => 'Choose a positive message ID boundary.',
            ]);
        }

        return (int) $validated;
    }
}
