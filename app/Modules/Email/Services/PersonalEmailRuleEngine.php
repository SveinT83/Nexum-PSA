<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Actions\CreatePersonalEmailRule;
use App\Modules\Email\Actions\PerformEmailRemoteOperation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailRuleExecutionAttempt;
use App\Modules\Email\Models\EmailRuleVersion;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class PersonalEmailRuleEngine
{
    /**
     * @var array<int, string>
     */
    private const CONDITION_FIELDS = ['from', 'from_domain', 'subject', 'to', 'cc'];

    /**
     * @var array<int, string>
     */
    private const ACTION_TYPES = [
        CreatePersonalEmailRule::ACTION_MOVE_TO_FOLDER,
        CreatePersonalEmailRule::ACTION_ARCHIVE,
    ];

    public function __construct(
        private readonly PerformEmailRemoteOperation $performRemoteOperation,
    ) {}

    public function process(
        EmailMessage $message,
        bool $allowProviderMutation = false,
    ): void {
        if (! Schema::hasColumn('email_rules', 'rule_kind') || ! Schema::hasTable('email_rule_execution_attempts')) {
            return;
        }

        $message->loadMissing(['account.owner']);
        $account = $message->account;

        if (! $account instanceof EmailAccount || ! $account->isPersonal() || ! $account->is_active) {
            return;
        }

        $owner = $account->owner;
        if (! $owner instanceof User || ! $owner->isActive()) {
            return;
        }

        $placement = $this->activeInboxPlacement($message, $account);
        if (! $placement) {
            return;
        }

        EmailRule::query()
            ->personalSimple()
            ->where('trigger', EmailRule::TRIGGER_INBOUND)
            ->where('routing_phase', EmailRule::ROUTING_PHASE_PERSONAL)
            ->where('is_active', true)
            ->where('owner_id', $owner->id)
            ->whereHas('accounts', fn ($accounts) => $accounts->whereKey($account->id))
            ->with(['accounts', 'publishedVersion'])
            ->orderBy('weight')
            ->orderBy('id')
            ->get()
            ->each(function (EmailRule $rule) use ($message, $account, $owner, $allowProviderMutation, &$placement): ?bool {
                if (! $placement || $placement->fresh()?->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE) {
                    return false;
                }

                $snapshot = $this->runtimeSnapshot($rule);
                if (! $this->snapshotCanRun($snapshot, $account, $owner) || ! $this->matches($message, $snapshot['conditions'])) {
                    return null;
                }

                $attempt = $this->startExecutionAttempt($rule, $snapshot, $message, $placement);
                if (! $attempt->wasRecentlyCreated) {
                    return null;
                }

                try {
                    $actionResults = $this->executeActions(
                        $placement,
                        $owner,
                        $snapshot['actions'],
                        $allowProviderMutation,
                    );
                    $failed = collect($actionResults)->contains(fn (array $result): bool => ($result['status'] ?? '') !== EmailRuleExecutionAttempt::STATUS_SUCCEEDED);

                    $this->finishExecutionAttempt(
                        $attempt,
                        $failed ? EmailRuleExecutionAttempt::STATUS_FAILED : EmailRuleExecutionAttempt::STATUS_SUCCEEDED,
                        $actionResults,
                    );

                    if (! $failed) {
                        $rule->forceFill([
                            'last_hit_at' => now(),
                            'hit_count' => ((int) $rule->hit_count) + 1,
                        ])->save();
                    }
                } catch (Throwable $exception) {
                    $this->finishExecutionAttempt($attempt, EmailRuleExecutionAttempt::STATUS_FAILED, [[
                        'position' => 0,
                        'type' => $snapshot['actions'][0]['type'] ?? '',
                        'status' => EmailRuleExecutionAttempt::STATUS_FAILED,
                        'reason' => 'email_rule_action_failed',
                    ]]);
                }

                $placement = $placement->fresh(['folder', 'account', 'message']);

                return null;
            });
    }

    private function activeInboxPlacement(EmailMessage $message, EmailAccount $account): ?EmailMailboxPlacement
    {
        return $message->placements()
            ->with(['folder', 'account', 'message'])
            ->where('account_id', $account->id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->where(function ($placements): void {
                $placements
                    ->whereHas('folder', fn ($folders) => $folders->where('role', EmailFolder::ROLE_INBOX))
                    ->orWhere(function ($legacy): void {
                        $legacy
                            ->whereNull('email_folder_id')
                            ->whereIn('folder_path', ['INBOX', 'Inbox', 'inbox']);
                    });
            })
            ->latest('id')
            ->first();
    }

    /**
     * @return array{
     *     uses_published_version: bool,
     *     version_id: int|null,
     *     version_number: int|null,
     *     routing_phase: string,
     *     rule_kind: string,
     *     owner_id: int|null,
     *     stop_processing: bool,
     *     conditions: array<int, array<string, mixed>>,
     *     actions: array<int, array<string, mixed>>,
     *     account_ids: array<int>
     * }
     */
    private function runtimeSnapshot(EmailRule $rule): array
    {
        $version = $rule->publishedVersion;

        if ($version instanceof EmailRuleVersion && $version->status === EmailRuleVersion::STATUS_PUBLISHED) {
            return [
                'uses_published_version' => true,
                'version_id' => $version->id,
                'version_number' => $version->version_number,
                'routing_phase' => $version->routing_phase,
                'rule_kind' => $version->rule_kind ?? $rule->rule_kind ?? EmailRule::KIND_ADMIN,
                'owner_id' => $version->owner_id ? (int) $version->owner_id : ($rule->owner_id ? (int) $rule->owner_id : null),
                'stop_processing' => (bool) $version->stop_processing,
                'conditions' => $version->conditions_json ?? [],
                'actions' => $version->actions_json ?? [],
                'account_ids' => collect($version->account_ids_json ?? [])
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all(),
            ];
        }

        return [
            'uses_published_version' => false,
            'version_id' => null,
            'version_number' => null,
            'routing_phase' => $rule->routing_phase,
            'rule_kind' => $rule->rule_kind ?? EmailRule::KIND_ADMIN,
            'owner_id' => $rule->owner_id ? (int) $rule->owner_id : null,
            'stop_processing' => (bool) $rule->stop_processing,
            'conditions' => $rule->conditions_json ?? [],
            'actions' => $rule->actions_json ?? [],
            'account_ids' => $rule->accounts
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotCanRun(array $snapshot, EmailAccount $account, User $owner): bool
    {
        if (($snapshot['routing_phase'] ?? '') !== EmailRule::ROUTING_PHASE_PERSONAL) {
            return false;
        }

        if (($snapshot['rule_kind'] ?? '') !== EmailRule::KIND_PERSONAL_SIMPLE || (int) ($snapshot['owner_id'] ?? 0) !== (int) $owner->id) {
            return false;
        }

        if (! in_array((int) $account->id, $snapshot['account_ids'] ?? [], true)) {
            return false;
        }

        $conditionsAreSafe = collect($this->flattenConditions($snapshot['conditions'] ?? []))
            ->every(fn (array $condition): bool => in_array((string) ($condition['field'] ?? ''), self::CONDITION_FIELDS, true));
        $actionsAreSafe = collect($snapshot['actions'] ?? [])
            ->isNotEmpty()
            && collect($snapshot['actions'] ?? [])->every(fn (array $action): bool => in_array((string) ($action['type'] ?? ''), self::ACTION_TYPES, true));

        return $conditionsAreSafe && $actionsAreSafe;
    }

    /**
     * @param  array<int, array<string, mixed>>  $conditions
     */
    private function matches(EmailMessage $message, array $conditions): bool
    {
        $groups = $this->conditionGroups($conditions);

        if ($groups === []) {
            return false;
        }

        $topMatch = $this->conditionTopMatch($conditions);
        $groupResults = collect($groups)
            ->map(function (array $group) use ($message): bool {
                $conditionResults = collect($group['conditions'])
                    ->map(fn (array $condition): bool => $this->matchesCondition($message, $condition));

                return ($group['match'] ?? 'all') === 'any'
                    ? $conditionResults->contains(true)
                    : $conditionResults->every(fn (bool $matched): bool => $matched);
            });

        return $topMatch === 'any'
            ? $groupResults->contains(true)
            : $groupResults->every(fn (bool $matched): bool => $matched);
    }

    private function matchesCondition(EmailMessage $message, array $condition): bool
    {
        $field = (string) ($condition['field'] ?? '');
        $operator = (string) ($condition['operator'] ?? 'contains');
        $expected = (string) ($condition['value'] ?? '');
        $actual = $this->fieldValue($message, $field);
        $actualLower = Str::lower($actual);
        $expectedLower = Str::lower($expected);

        return match ($operator) {
            'equals' => $actualLower === $expectedLower,
            'starts_with' => str_starts_with($actualLower, $expectedLower),
            'ends_with' => str_ends_with($actualLower, $expectedLower),
            default => $expectedLower === '' || str_contains($actualLower, $expectedLower),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function flattenConditions(array $conditions): array
    {
        return collect($this->conditionGroups($conditions))
            ->flatMap(fn (array $group): array => $group['conditions'])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{name: string|null, match: string, conditions: array<int, array<string, mixed>>}>
     */
    private function conditionGroups(array $conditions): array
    {
        if (array_is_list($conditions)) {
            return [[
                'name' => null,
                'match' => 'all',
                'conditions' => $conditions,
            ]];
        }

        return collect($conditions['groups'] ?? [])
            ->filter(fn (mixed $group): bool => is_array($group))
            ->map(fn (array $group): array => [
                'name' => isset($group['name']) ? (string) $group['name'] : null,
                'match' => ($group['match'] ?? 'all') === 'any' ? 'any' : 'all',
                'conditions' => collect($group['conditions'] ?? [])
                    ->filter(fn (mixed $condition): bool => is_array($condition))
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $group): bool => $group['conditions'] !== [])
            ->values()
            ->all();
    }

    private function conditionTopMatch(array $conditions): string
    {
        if (array_is_list($conditions)) {
            return 'all';
        }

        return ($conditions['match'] ?? 'all') === 'any' ? 'any' : 'all';
    }

    private function fieldValue(EmailMessage $message, string $field): string
    {
        return match ($field) {
            'from' => (string) $message->from_email,
            'from_domain' => Str::lower((string) str($message->from_email)->after('@')),
            'to' => $this->recipientFieldValue((array) $message->to_json),
            'cc' => $this->recipientFieldValue((array) $message->cc_json),
            'subject' => (string) $message->subject,
            default => '',
        };
    }

    private function recipientFieldValue(array $recipients): string
    {
        return collect($recipients)
            ->map(fn ($recipient) => is_array($recipient)
                ? trim((string) (($recipient['name'] ?? '').' '.($recipient['email'] ?? $recipient['address'] ?? '')))
                : (string) $recipient)
            ->filter()
            ->implode(' ');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function startExecutionAttempt(
        EmailRule $rule,
        array $snapshot,
        EmailMessage $message,
        EmailMailboxPlacement $placement,
    ): EmailRuleExecutionAttempt {
        $versionKey = $snapshot['version_id'] ?: 'live';
        $idempotencyKey = hash('sha256', implode('|', [
            'personal-email-rule',
            $rule->id,
            $versionKey,
            $message->id,
            $placement->id,
        ]));

        return EmailRuleExecutionAttempt::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'email_rule_id' => $rule->id,
                'email_rule_version_id' => $snapshot['version_id'],
                'email_message_id' => $message->id,
                'email_mailbox_placement_id' => $placement->id,
                'routing_phase' => $snapshot['routing_phase'],
                'status' => EmailRuleExecutionAttempt::STATUS_RUNNING,
                'matched' => true,
                'stop_processing' => false,
                'conditions_json' => $snapshot['conditions'],
                'actions_json' => $snapshot['actions'],
                'started_at' => now(),
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @return array<int, array<string, mixed>>
     */
    private function executeActions(
        EmailMailboxPlacement $placement,
        User $owner,
        array $actions,
        bool $allowProviderMutation,
    ): array {
        $results = [];

        foreach ($actions as $index => $action) {
            $type = (string) ($action['type'] ?? '');
            $targetFolder = null;
            $operation = match ($type) {
                CreatePersonalEmailRule::ACTION_ARCHIVE => PerformEmailRemoteOperation::ARCHIVE,
                CreatePersonalEmailRule::ACTION_MOVE_TO_FOLDER => PerformEmailRemoteOperation::MOVE,
                default => '',
            };

            if (! $allowProviderMutation) {
                $results[] = [
                    'position' => (int) $index,
                    'type' => $type,
                    'status' => EmailRuleExecutionAttempt::STATUS_FAILED,
                    'reason' => 'provider_mutation_not_authorized',
                ];

                $this->appendNotRunActions($results, $actions, (int) $index);

                break;
            }

            if (in_array($operation, [
                PerformEmailRemoteOperation::ARCHIVE,
                PerformEmailRemoteOperation::MOVE,
            ], true)) {
                $targetFolder = EmailFolder::query()
                    ->whereKey((int) ($action['target_folder_id'] ?? 0))
                    ->where('account_id', $placement->account_id)
                    ->where('is_selectable', true)
                    ->where('sync_enabled', true)
                    ->when(
                        $operation === PerformEmailRemoteOperation::ARCHIVE,
                        fn ($folders) => $folders->where('role', EmailFolder::ROLE_ARCHIVE),
                    )
                    ->first();
            }

            try {
                $remoteOperation = $this->performRemoteOperation->handle($placement, $operation, $owner, $targetFolder);
                $succeeded = $remoteOperation->status === EmailRemoteOperation::STATUS_SUCCEEDED;
                $results[] = [
                    'position' => (int) $index,
                    'type' => $type,
                    'status' => $succeeded
                        ? EmailRuleExecutionAttempt::STATUS_SUCCEEDED
                        : EmailRuleExecutionAttempt::STATUS_FAILED,
                    'remote_operation_id' => $remoteOperation->id,
                    'remote_operation_status' => $remoteOperation->status,
                    'target_folder_path' => $remoteOperation->target_folder_path,
                    'reason' => $succeeded
                        ? null
                        : ($remoteOperation->error_code ?: 'provider_rule_action_not_acknowledged'),
                ];
            } catch (Throwable) {
                $succeeded = false;
                $results[] = [
                    'position' => (int) $index,
                    'type' => $type,
                    'status' => EmailRuleExecutionAttempt::STATUS_FAILED,
                    'reason' => 'provider_rule_action_rejected',
                ];
            }

            if (! $succeeded) {
                $this->appendNotRunActions($results, $actions, (int) $index);

                break;
            }
        }

        return $results;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @param  array<int, array<string, mixed>>  $actions
     */
    private function appendNotRunActions(array &$results, array $actions, int $failedPosition): void
    {
        foreach (array_slice($actions, $failedPosition + 1, null, true) as $position => $action) {
            $results[] = [
                'position' => (int) $position,
                'type' => (string) ($action['type'] ?? ''),
                'status' => EmailRuleExecutionAttempt::STATUS_NOT_RUN,
                'reason' => 'not_run_after_action_failure',
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $actionResults
     */
    private function finishExecutionAttempt(EmailRuleExecutionAttempt $attempt, string $status, array $actionResults): void
    {
        $attempt->forceFill([
            'status' => $status,
            'action_results_json' => $actionResults,
            'finished_at' => now(),
        ])->save();
    }
}
