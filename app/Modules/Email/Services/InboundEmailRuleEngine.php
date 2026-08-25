<?php

namespace App\Modules\Email\Services;

use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Email\Actions\ApplyEmailConversationRuleClassification;
use App\Modules\Email\Actions\BuildEmailSmartInboxRulePrefill;
use App\Modules\Email\Actions\PerformEmailRemoteOperation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailRuleExecutionAttempt;
use App\Modules\Email\Models\EmailRuleLog;
use App\Modules\Email\Models\EmailRuleVersion;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Signal\Actions\RecordSignal;
use App\Modules\Signal\Models\Signal;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Actions\CreateTicketFromInboundEmail;
use App\Modules\Ticket\Actions\LinkInboundEmailToTicket;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketType;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InboundEmailRuleEngine
{
    public function __construct(
        private readonly LinkInboundEmailToTicket $linkInboundEmailToTicket,
        private readonly CreateTicketFromInboundEmail $createTicketFromInboundEmail,
        private readonly RecordSignal $recordSignal,
        private readonly TrustedSenderAuthenticationFacts $trustedSenderAuthenticationFacts,
        private readonly ApplyEmailConversationRuleClassification $applyConversationClassification,
        private readonly MailboxAccess $mailboxAccess,
        private readonly PerformEmailRemoteOperation $performRemoteOperation,
    ) {}

    public function processPreclassification(
        EmailMessage $message,
        bool $allowProviderMutation = false,
    ): bool {
        if ($message->ticket_id !== null || ! $this->allowsInboundAutomation($message) || ! Schema::hasTable('email_rules')) {
            return false;
        }

        return $this->runConfiguredRules(
            $message,
            EmailRule::ROUTING_PHASE_PRECLASSIFICATION,
            $allowProviderMutation,
        );
    }

    public function process(
        EmailMessage $message,
        bool $allowProviderMutation = false,
        int $depth = 0,
    ): void {
        if ($depth > 5) {
            \Illuminate\Support\Facades\Log::warning('Email rule loop detected and stopped.', [
                'message_id' => $message->id,
                'depth' => $depth,
            ]);

            return;
        }

        if ($message->ticket_id !== null || ! $this->allowsInboundAutomation($message)) {
            return;
        }

        if (! Schema::hasTable('email_rules')) {
            if ($this->linkBySalesHeaderReferences($message) || $this->linkBySalesKey($message->fresh())) {
                return;
            }

            $this->linkByHeaderReferences($message);
            $this->linkByTicketKey($message->fresh());

            return;
        }

        if ($this->linkBySalesHeaderReferences($message) || $this->linkBySalesKey($message->fresh())) {
            return;
        }

        $stopped = $this->runConfiguredRules(
            $message,
            EmailRule::ROUTING_PHASE_NORMAL,
            $allowProviderMutation,
        );

        if (! $stopped) {
            $this->routeByDefaultTicketPolicy($message);
        }
    }

    private function runConfiguredRules(
        EmailMessage $message,
        string $routingPhase,
        bool $allowProviderMutation,
    ): bool {
        if ($message->ticket_id !== null) {
            return true;
        }

        $stopped = false;

        EmailRule::query()
            ->adminManaged()
            ->where('trigger', EmailRule::TRIGGER_INBOUND)
            ->where('routing_phase', $routingPhase)
            ->where('is_active', true)
            ->with(['accounts', 'publishedVersion'])
            ->orderBy('weight')
            ->orderBy('id')
            ->get()
            ->each(function (EmailRule $rule) use ($message, $allowProviderMutation, &$stopped) {
                if ($stopped || $message->fresh()->ticket_id !== null) {
                    return false;
                }

                $snapshot = $this->runtimeSnapshot($rule);

                if (! $this->ruleAppliesToMessageAccount($rule, $message, $snapshot)) {
                    return null;
                }

                if (! $this->matches($message, $snapshot['conditions'])) {
                    return null;
                }

                $attempt = $this->startExecutionAttempt($rule, $snapshot, $message);

                if ($attempt && ! $attempt->wasRecentlyCreated) {
                    if ($attempt->status === EmailRuleExecutionAttempt::STATUS_SUCCEEDED && $attempt->stop_processing) {
                        $stopped = true;

                        return false;
                    }

                    return null;
                }

                try {
                    $actionResults = $this->executeActions(
                        $message,
                        $rule,
                        $snapshot,
                        $allowProviderMutation,
                    );
                } catch (\Throwable $exception) {
                    $this->finishExecutionAttempt($attempt, EmailRuleExecutionAttempt::STATUS_FAILED, [
                        [
                            'status' => EmailRuleExecutionAttempt::STATUS_FAILED,
                            'reason' => 'email_rule_action_failed',
                        ],
                    ]);

                    throw $exception;
                }

                $actionFailed = collect($actionResults)
                    ->contains(fn (array $result): bool => ($result['status'] ?? '') === EmailRuleExecutionAttempt::STATUS_FAILED);

                if ($actionFailed) {
                    $this->finishExecutionAttempt($attempt, EmailRuleExecutionAttempt::STATUS_FAILED, $actionResults);

                    EmailRuleLog::create([
                        'email_rule_id' => $rule->id,
                        'email_message_id' => $message->id,
                        'status' => 'failed',
                        'actions_json' => $snapshot['actions'],
                        'message' => 'Inbound email rule matched, but at least one guarded action failed.',
                    ]);

                    return null;
                }

                $rule->forceFill([
                    'last_hit_at' => now(),
                    'hit_count' => $rule->hit_count + 1,
                ])->save();

                $this->finishExecutionAttempt($attempt, EmailRuleExecutionAttempt::STATUS_SUCCEEDED, $actionResults);

                EmailRuleLog::create([
                    'email_rule_id' => $rule->id,
                    'email_message_id' => $message->id,
                    'status' => 'matched',
                    'actions_json' => $snapshot['actions'],
                    'message' => $snapshot['version_number']
                        ? 'Inbound email rule matched published version v'.$snapshot['version_number'].'.'
                        : 'Inbound email rule matched compatibility live rule.',
                ]);

                if ($snapshot['stop_processing']) {
                    $stopped = true;

                    return false;
                }

                return null;
            });

        return $stopped || $message->fresh()->ticket_id !== null;
    }

    public function previewRule(EmailRule $rule, EmailMessage $message): array
    {
        $rule->loadMissing(['accounts', 'publishedVersion']);

        $snapshot = $this->runtimeSnapshot($rule);
        $accountMatched = $this->ruleAppliesToMessageAccount($rule, $message, $snapshot);
        $conditions = $this->conditionDetails($message, $snapshot['conditions']);
        $conditionsMatched = $this->matches($message, $snapshot['conditions']);
        $matched = $accountMatched && $conditionsMatched;

        return [
            'rule_id' => $rule->id,
            'rule_name' => $snapshot['name'],
            'version_id' => $snapshot['version_id'],
            'version_number' => $snapshot['version_number'],
            'version_status' => $snapshot['version_status'],
            'message_id' => $message->id,
            'routing_phase' => $snapshot['routing_phase'],
            'account_scope_matched' => $accountMatched,
            'matched' => $matched,
            'stop_processing' => $snapshot['stop_processing'],
            'conditions' => $conditions,
            'actions' => collect($snapshot['actions'])
                ->values()
                ->map(fn (array $action, int $index): array => [
                    'position' => $index,
                    'type' => $action['type'] ?? '',
                    'value' => $action['value'] ?? $action['signal_type'] ?? null,
                    'status' => $matched ? 'would_run' : 'not_run',
                ])
                ->all(),
        ];
    }

    public function allowsInboundAutomation(EmailMessage $message): bool
    {
        $message->loadMissing('account');
        $account = $message->account;

        if (! $account) {
            return false;
        }

        if (EmailFolder::inferRole((string) $message->mailbox) !== EmailFolder::ROLE_INBOX) {
            return false;
        }

        if (! Schema::hasColumn('email_accounts', 'ticket_ingress_enabled')) {
            return true;
        }

        return $account->allowsTicketIngress();
    }

    private function ruleAppliesToMessageAccount(EmailRule $rule, EmailMessage $message, ?array $snapshot = null): bool
    {
        if (! Schema::hasTable('email_rule_accounts')) {
            return true;
        }

        if (! $message->account_id) {
            return false;
        }

        if ($snapshot && $snapshot['uses_published_version']) {
            return in_array((int) $message->account_id, $snapshot['account_ids'], true);
        }

        if ($rule->accounts->isNotEmpty()) {
            return $rule->accounts->contains(fn (EmailAccount $account): bool => (int) $account->id === (int) $message->account_id);
        }

        // Compatibility for programmatic legacy rules created without the new
        // pivot. The account policy still blocks personal or non-ingress mail.
        return $this->allowsInboundAutomation($message);
    }

    public function ticketKeyFromSubject(?string $subject): ?string
    {
        if (! $subject) {
            return null;
        }

        preg_match('/\bTD-\d{4}-\d{6}\b/i', $subject, $matches);

        return isset($matches[0]) ? strtoupper($matches[0]) : null;
    }

    public function salesKeyFromSubject(?string $subject): ?string
    {
        if (! $subject) {
            return null;
        }

        preg_match('/\bSO-\d{4}-[A-Z0-9]{6}\b/i', $subject, $matches);

        return isset($matches[0]) ? strtoupper($matches[0]) : null;
    }

    private function matches(EmailMessage $message, array $conditions): bool
    {
        $groups = $this->conditionGroups($conditions);

        if ($groups === []) {
            return false;
        }

        $topMatch = $this->conditionTopMatch($conditions);
        $groupResults = collect($groups)
            ->map(function (array $group) use ($message): bool {
                $details = $this->conditionDetails($message, $group['conditions']);

                return ($group['match'] ?? 'all') === 'any'
                    ? collect($details)->contains(fn (array $condition): bool => (bool) $condition['matched'])
                    : collect($details)->every(fn (array $condition): bool => (bool) $condition['matched']);
            });

        return $topMatch === 'any'
            ? $groupResults->contains(true)
            : $groupResults->every(fn (bool $matched): bool => $matched);
    }

    private function conditionDetails(EmailMessage $message, array $conditions): array
    {
        if (! array_is_list($conditions) && isset($conditions['groups'])) {
            return collect($this->conditionGroups($conditions))
                ->flatMap(function (array $group, int $groupIndex) use ($message): array {
                    return collect($this->conditionDetails($message, $group['conditions']))
                        ->map(function (array $condition) use ($group, $groupIndex): array {
                            $condition['group'] = $group['name'] ?? 'Group '.($groupIndex + 1);
                            $condition['group_match'] = $group['match'] ?? 'all';

                            return $condition;
                        })
                        ->all();
                })
                ->values()
                ->all();
        }

        return collect($conditions)
            ->map(function (array $condition) use ($message): array {
                $field = $condition['field'] ?? '';
                $operator = $condition['operator'] ?? 'contains';
                $expected = (string) ($condition['value'] ?? '');
                $actual = $this->fieldValue($message, $field);

                return [
                    'field' => $field,
                    'operator' => $operator,
                    'expected' => $expected,
                    'actual_preview' => Str::limit($actual, 500),
                    'matched' => $this->matchCondition($actual, $operator, $expected, $message, $field),
                ];
            })
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

    private function matchCondition(string $actual, string $operator, string $expected, EmailMessage $message, string $field): bool
    {
        $actualLower = mb_strtolower($actual);
        $expectedLower = mb_strtolower($expected);

        return match ($operator) {
            'equals' => $actualLower === $expectedLower,
            'not_equals' => $actualLower !== $expectedLower,
            'starts_with' => str_starts_with($actualLower, $expectedLower),
            'ends_with' => str_ends_with($actualLower, $expectedLower),
            'regex' => $expected !== '' && @preg_match('/'.str_replace('/', '\/', $expected).'/i', $actual) === 1,
            'present' => $field === 'has_ticket_key'
                ? $this->ticketKeyFromSubject($message->subject) !== null
                : $actual !== '',
            default => $expectedLower === '' || str_contains($actualLower, $expectedLower),
        };
    }

    private function fieldValue(EmailMessage $message, string $field): string
    {
        return match ($field) {
            'from' => (string) $message->from_email,
            'from_domain' => strtolower((string) str($message->from_email)->after('@')),
            'to' => $this->recipientFieldValue((array) $message->to_json),
            'cc' => $this->recipientFieldValue((array) $message->cc_json),
            'subject' => (string) $message->subject,
            'body' => (string) $message->body_text,
            'message_id' => (string) $message->message_id,
            'is_reply' => $message->in_reply_to || $message->references || str_starts_with(strtolower((string) $message->subject), 're:') ? '1' : '',
            'has_ticket_key' => $this->ticketKeyFromSubject($message->subject) ?? '',
            'has_sales_key' => $this->salesKeyFromSubject($message->subject) ?? '',
            default => '',
        };
    }

    private function executeActions(
        EmailMessage $message,
        EmailRule $rule,
        array $snapshot,
        bool $allowProviderMutation,
    ): array {
        $results = [];

        foreach ($snapshot['actions'] as $index => $action) {
            $type = $action['type'] ?? '';
            $value = $action['value'] ?? null;

            if (in_array($type, [
                BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_ARCHIVE,
                BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
            ], true)) {
                $providerResult = $this->executeProviderCleanupAction(
                    $message,
                    $snapshot,
                    $action,
                    (int) $index,
                    $allowProviderMutation,
                );
                $results[] = $providerResult;

                if (($providerResult['status'] ?? '') === EmailRuleExecutionAttempt::STATUS_FAILED) {
                    foreach (array_slice($snapshot['actions'], ((int) $index) + 1, null, true) as $laterIndex => $laterAction) {
                        $results[] = [
                            'position' => (int) $laterIndex,
                            'type' => (string) ($laterAction['type'] ?? ''),
                            'status' => EmailRuleExecutionAttempt::STATUS_NOT_RUN,
                            'reason' => 'not_run_after_provider_cleanup_failure',
                        ];
                    }

                    break;
                }

                continue;
            }

            try {
                if (! $this->executeLocalAction($message, $rule, $action, (int) $index)) {
                    throw new \UnexpectedValueException('Unsupported Email rule action.');
                }

                $results[] = [
                    'position' => (int) $index,
                    'type' => $type,
                    'status' => EmailRuleExecutionAttempt::STATUS_SUCCEEDED,
                ];
            } catch (\Throwable) {
                $results[] = [
                    'position' => (int) $index,
                    'type' => (string) $type,
                    'status' => EmailRuleExecutionAttempt::STATUS_FAILED,
                    'reason' => 'email_rule_action_failed',
                ];

                foreach (array_slice($snapshot['actions'], ((int) $index) + 1, null, true) as $laterIndex => $laterAction) {
                    $results[] = [
                        'position' => (int) $laterIndex,
                        'type' => (string) ($laterAction['type'] ?? ''),
                        'status' => EmailRuleExecutionAttempt::STATUS_NOT_RUN,
                        'reason' => 'not_run_after_action_failure',
                    ];
                }

                break;
            }
        }

        return $results;
    }

    /** @param  array<string, mixed>  $action */
    private function executeLocalAction(
        EmailMessage $message,
        EmailRule $rule,
        array $action,
        int $position,
    ): bool {
        $type = (string) ($action['type'] ?? '');
        $value = (string) ($action['value'] ?? '');

        switch ($type) {
            case 'link_ticket_by_subject_token':
                $this->linkByTicketKey($message);

                return true;
            case 'link_sales_by_subject_token':
                $this->linkBySalesKey($message);

                return true;
            case 'create_ticket':
                $this->createTicket($message, $value);

                return true;
            case 'archive':
                $message->forceFill(['state' => 'archived'])->save();

                return true;
            case 'tag':
            case 'tag_message':
                $this->tag($message, $value);

                return true;
            case ApplyEmailConversationRuleClassification::ACTION_TAG_CONVERSATION:
            case ApplyEmailConversationRuleClassification::ACTION_SET_CONVERSATION_CATEGORY:
                $this->applyConversationClassification->handle(
                    $message,
                    $rule,
                    $type,
                    $value,
                    $position,
                );

                return true;
            case 'emit_signal':
                $this->emitSignal($message, $rule, $action, $position);

                return true;
            default:
                return false;
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function executeProviderCleanupAction(
        EmailMessage $message,
        array $snapshot,
        array $action,
        int $position,
        bool $allowProviderMutation,
    ): array {
        $type = (string) ($action['type'] ?? '');
        $failure = fn (string $reason): array => [
            'position' => $position,
            'type' => $type,
            'status' => EmailRuleExecutionAttempt::STATUS_FAILED,
            'reason' => $reason,
        ];
        if (! $allowProviderMutation) {
            return $failure('provider_mutation_not_authorized');
        }

        $publisherId = (int) ($snapshot['published_by'] ?? 0);
        $publisher = $publisherId > 0
            ? User::query()->whereKey($publisherId)->where('status', User::STATUS_ACTIVE)->first()
            : null;
        $message->loadMissing('account');
        $account = $message->account;

        if (! $publisher
            || ! $publisher->can('email.rule_manage')
            || ! $account
            || ! $account->is_active
            || ! in_array((int) $account->id, $snapshot['account_ids'] ?? [], true)
            || ! $this->mailboxAccess->canAccessAccount($publisher, $account, MailboxAccess::ORGANIZE)) {
            return $failure('provider_cleanup_authorization_revoked');
        }

        $placements = EmailMailboxPlacement::query()
            ->with(['account', 'folder', 'message'])
            ->where('email_message_id', $message->id)
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
            ->orderBy('id')
            ->get();

        if ($placements->count() !== 1) {
            return $failure('provider_cleanup_source_stale_or_ambiguous');
        }

        $placement = $placements->first();
        $targetFolder = EmailFolder::query()
            ->whereKey((int) ($action['target_folder_id'] ?? 0))
            ->where('account_id', $account->id)
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->when(
                $type === BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_ARCHIVE,
                fn ($folders) => $folders->where('role', EmailFolder::ROLE_ARCHIVE),
            )
            ->first();

        if (! $targetFolder || (int) $targetFolder->id === (int) $placement->email_folder_id) {
            return $failure('provider_cleanup_target_stale');
        }

        try {
            $operation = $this->performRemoteOperation->handle(
                $placement,
                $type === BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_ARCHIVE
                    ? PerformEmailRemoteOperation::ARCHIVE
                    : PerformEmailRemoteOperation::MOVE,
                $publisher,
                $targetFolder,
            );
        } catch (\Throwable) {
            return $failure('provider_cleanup_operation_rejected');
        }

        return [
            'position' => $position,
            'type' => $type,
            'status' => $operation->status === EmailRemoteOperation::STATUS_SUCCEEDED
                ? EmailRuleExecutionAttempt::STATUS_SUCCEEDED
                : EmailRuleExecutionAttempt::STATUS_FAILED,
            'remote_operation_id' => (int) $operation->id,
            'remote_operation_status' => $operation->status,
            'before' => [
                'folder_id' => $placement->email_folder_id,
            ],
            'reason' => $operation->status === EmailRemoteOperation::STATUS_SUCCEEDED
                ? null
                : ($operation->error_code ?: 'provider_cleanup_not_acknowledged'),
        ];
    }

    private function runtimeSnapshot(EmailRule $rule): array
    {
        $version = $rule->publishedVersion;

        if ($version instanceof EmailRuleVersion && $version->status === EmailRuleVersion::STATUS_PUBLISHED) {
            return [
                'uses_published_version' => true,
                'version_id' => $version->id,
                'version_number' => $version->version_number,
                'version_status' => $version->status,
                'name' => $version->name,
                'routing_phase' => $version->routing_phase,
                'rule_kind' => $version->rule_kind ?? $rule->rule_kind ?? EmailRule::KIND_ADMIN,
                'owner_id' => $version->owner_id ? (int) $version->owner_id : ($rule->owner_id ? (int) $rule->owner_id : null),
                'published_by' => $version->published_by ? (int) $version->published_by : null,
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
            'version_status' => 'live_compatibility',
            'name' => $rule->name,
            'routing_phase' => $rule->routing_phase,
            'rule_kind' => $rule->rule_kind ?? EmailRule::KIND_ADMIN,
            'owner_id' => $rule->owner_id ? (int) $rule->owner_id : null,
            'published_by' => $rule->published_by ? (int) $rule->published_by : null,
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

    private function startExecutionAttempt(EmailRule $rule, array $snapshot, EmailMessage $message): ?EmailRuleExecutionAttempt
    {
        if (! Schema::hasTable('email_rule_execution_attempts')) {
            return null;
        }

        $message->loadMissing('latestPlacement');
        $placementId = $message->latestPlacement?->id;
        $versionKey = $snapshot['version_id'] ?: 'live';
        $idempotencyKey = hash('sha256', implode('|', [
            'email-rule',
            $rule->id,
            $versionKey,
            $message->id,
            $placementId ?: 0,
        ]));

        return EmailRuleExecutionAttempt::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'email_rule_id' => $rule->id,
                'email_rule_version_id' => $snapshot['version_id'],
                'email_message_id' => $message->id,
                'email_mailbox_placement_id' => $placementId,
                'routing_phase' => $snapshot['routing_phase'],
                'status' => EmailRuleExecutionAttempt::STATUS_RUNNING,
                'matched' => true,
                'stop_processing' => $snapshot['stop_processing'],
                'conditions_json' => $snapshot['conditions'],
                'actions_json' => $snapshot['actions'],
                'started_at' => now(),
            ],
        );
    }

    private function finishExecutionAttempt(?EmailRuleExecutionAttempt $attempt, string $status, array $actionResults): void
    {
        if (! $attempt || ! $attempt->wasRecentlyCreated) {
            return;
        }

        $attempt->forceFill([
            'status' => $status,
            'action_results_json' => $actionResults,
            'finished_at' => now(),
        ])->save();
    }

    private function emitSignal(EmailMessage $message, EmailRule $rule, array $action, int $actionIndex): ?Signal
    {
        $signalType = $this->normalizeSignalType($action['signal_type'] ?? $action['value'] ?? '');

        if ($signalType === '') {
            return null;
        }

        $existing = Signal::query()
            ->where('source_domain', 'email')
            ->where('source_type', $message->getMorphClass())
            ->where('source_id', $message->id)
            ->where('signal_type', $signalType)
            ->where('payload->email_rule_id', $rule->id)
            ->where('payload->email_rule_action_index', $actionIndex)
            ->first();

        if ($existing) {
            return $existing;
        }

        $contact = $this->contactFromEmailAddress($message->from_email);
        $message->loadMissing('tags');

        return $this->recordSignal->handle([
            'source_domain' => 'email',
            'source_type' => $message->getMorphClass(),
            'source_id' => $message->id,
            'subject_type' => $contact?->getMorphClass(),
            'subject_id' => $contact?->id,
            'contact_id' => $contact?->id,
            'client_id' => $this->clientIdForContact($contact),
            'signal_type' => $signalType,
            'severity' => $action['severity'] ?? 'info',
            'confidence' => max(0, min(100, (int) ($action['confidence'] ?? 100))),
            'summary' => $action['summary'] ?? 'Email rule signal: '.str_replace('_', ' ', $signalType),
            'payload' => [
                'email_message_id' => $message->id,
                'email_rule_id' => $rule->id,
                'email_rule_name' => $rule->name,
                'email_rule_action_index' => $actionIndex,
                'from_email' => $message->from_email,
                'subject' => $message->subject,
                'state' => $message->state,
                'ticket_id' => $message->fresh()->ticket_id,
                'tags' => $message->tags->pluck('name')->values()->all(),
                'note' => $action['payload_note'] ?? null,
                'trusted_auth' => $this->trustedSenderAuthenticationFacts->forMessage($message),
            ],
            'occurred_at' => $message->received_at ?: now(),
        ]);
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

    private function createTicket(EmailMessage $message, string $queueValue = '', ?string $typeSlug = null): void
    {
        // create_ticket is only for unmatched inbound mail; replies must stay on the existing ticket thread.
        $this->linkByHeaderReferences($message);
        $message = $message->fresh();

        if ($message->ticket_id !== null || $message->state === 'archived' || $this->isTicketSuppressedTagged($message)) {
            return;
        }

        $this->linkByTicketKey($message);
        $message = $message->fresh();

        if ($message->ticket_id !== null || $message->state === 'archived' || $this->isTicketSuppressedTagged($message)) {
            return;
        }

        // Explicit queue values win; otherwise a queue can be inferred from To/Cc recipients.
        $queue = $queueValue !== ''
            ? TicketQueue::query()
                ->whereKey($queueValue)
                ->orWhere('slug', $queueValue)
                ->first()
            : $this->queueFromRecipients($message);
        $ticketType = $typeSlug
            ? TicketType::query()->where('slug', $typeSlug)->where('is_active', true)->first()
            : null;

        $this->createTicketFromInboundEmail->handle($message->fresh(), $queue, $ticketType);
    }

    private function linkByTicketKey(EmailMessage $message): void
    {
        $ticketKey = $this->ticketKeyFromSubject($message->subject);

        if (! $ticketKey) {
            return;
        }

        $ticket = Ticket::where('ticket_key', $ticketKey)->first();

        if (! $ticket) {
            return;
        }

        $this->linkInboundEmailToTicket->handle($message->fresh(), $ticket);
    }

    private function linkBySalesKey(EmailMessage $message): bool
    {
        $salesKey = $this->salesKeyFromSubject($message->subject);

        if (! $salesKey) {
            return false;
        }

        $opportunity = SalesOpportunity::query()->where('opportunity_key', $salesKey)->first();

        if (! $opportunity) {
            return false;
        }

        $this->createSalesInboundActivity($message->fresh(), $opportunity);

        return true;
    }

    private function linkByHeaderReferences(EmailMessage $message): void
    {
        $messageIds = $this->referencedMessageIds($message);

        if (empty($messageIds)) {
            return;
        }

        $logs = EmailLog::query()
            ->where('direction', 'outbound')
            ->where('scope', 'tickets')
            ->whereIn('rfc_message_id', $messageIds)
            ->latest('id')
            ->get();

        foreach ($logs as $log) {
            $ticketMessageId = (int) ($log->context_json['ticket_message_id'] ?? 0);

            if (! $ticketMessageId) {
                continue;
            }

            $ticketMessage = TicketMessage::with('ticket')->find($ticketMessageId);

            if (! $ticketMessage?->ticket) {
                continue;
            }

            $this->linkInboundEmailToTicket->handle($message->fresh(), $ticketMessage->ticket);

            return;
        }
    }

    private function linkBySalesHeaderReferences(EmailMessage $message): bool
    {
        $messageIds = $this->referencedMessageIds($message);

        if (empty($messageIds)) {
            return false;
        }

        $logs = EmailLog::query()
            ->where('direction', 'outbound')
            ->where('scope', 'sales')
            ->whereIn('rfc_message_id', $messageIds)
            ->latest('id')
            ->get();

        foreach ($logs as $log) {
            $activityId = (int) ($log->context_json['sales_activity_id'] ?? 0);

            if (! $activityId) {
                continue;
            }

            $activity = SalesActivity::with('opportunity')->find($activityId);

            if (! $activity?->opportunity) {
                continue;
            }

            $this->createSalesInboundActivity($message->fresh(), $activity->opportunity);

            return true;
        }

        return false;
    }

    private function createSalesInboundActivity(EmailMessage $message, SalesOpportunity $opportunity): void
    {
        if (SalesActivity::query()->where('metadata->email_message_id', $message->id)->exists()) {
            return;
        }

        SalesActivity::query()->create([
            'opportunity_id' => $opportunity->id,
            'actor_id' => null,
            'type' => 'email_in',
            'direction' => 'inbound',
            'subject' => $message->subject,
            'body' => $this->salesInboundBody($message),
            'is_unread' => true,
            'read_at' => null,
            'metadata' => [
                'email_message_id' => $message->id,
                'from_email' => $message->from_email,
                'from_name' => $message->from_name,
                'message_id' => $message->message_id,
                'in_reply_to' => $message->in_reply_to,
                'references' => $message->references,
            ],
        ]);

        $opportunity->forceFill(['is_unread' => true])->save();
        $message->forceFill(['state' => 'archived'])->save();
    }

    private function salesInboundBody(EmailMessage $message): string
    {
        $body = $message->body_text ?: trim(strip_tags((string) $message->body_html_sanitized));
        $body = BodyNormalizer::stripQuotedHistory($body);

        return $body !== '' ? $body : '[Inbound email had no readable body.]';
    }

    private function referencedMessageIds(EmailMessage $message): array
    {
        $source = trim((string) $message->in_reply_to.' '.(string) $message->references);
        preg_match_all('/<([^>]+)>/', $source, $bracketedMatches);
        $sourceWithoutBracketedIds = preg_replace('/<[^>]+>/', ' ', $source) ?: '';
        preg_match_all('/[^\s<>;,]+@[^\s<>;,]+/', $sourceWithoutBracketedIds, $bareMatches);

        return collect($bracketedMatches[1] ?? [])
            ->merge($bareMatches[0] ?? [])
            ->map(fn ($messageId) => trim($messageId, " \t\n\r\0\x0B<>;,"))
            ->filter()
            ->flatMap(fn (string $messageId): array => [$messageId, '<'.$messageId.'>'])
            ->unique()
            ->values()
            ->all();
    }

    private function routeByDefaultTicketPolicy(EmailMessage $message): void
    {
        $message = $message->fresh();

        if ($message->ticket_id !== null || $message->state === 'archived') {
            return;
        }

        $this->linkByHeaderReferences($message);

        $message = $message->fresh();

        if ($message->ticket_id !== null || $message->state === 'archived') {
            return;
        }

        $this->linkByTicketKey($message);

        $message = $message->fresh();

        if ($message->ticket_id !== null || $message->state === 'archived' || $this->isTicketSuppressedTagged($message)) {
            return;
        }

        // Nexum PSA is ticket-first: known contacts become support tickets, unknown senders become lead tickets.
        $this->createTicket($message, '', $this->senderIsKnownClientContact($message) ? null : 'lead');
    }

    private function senderIsKnownClientContact(EmailMessage $message): bool
    {
        if (! $message->from_email) {
            return false;
        }

        return ClientUser::query()
            ->where('email', $message->from_email)
            ->where('active', true)
            ->exists();
    }

    private function isTicketSuppressedTagged(EmailMessage $message): bool
    {
        $message->loadMissing('tags');

        return $message->tags->contains(fn (Tag $tag) => in_array(strtolower($tag->slug ?: $tag->name), ['not-ticket', 'spam', 'junk'], true));
    }

    private function tag(EmailMessage $message, string $tag): void
    {
        if ($tag === '') {
            return;
        }

        $tagModel = Tag::firstOrCreate(
            ['name' => $tag],
            [
                'slug' => Str::slug($tag),
                'color' => '#6c757d',
                'active' => true,
            ]
        );

        if (! $message->tags()->where('tags.id', $tagModel->id)->exists()) {
            $message->tags()->attach($tagModel->id, ['module' => 'email']);
        }
    }

    private function contactFromEmailAddress(?string $email): ?Contact
    {
        if (! $email) {
            return null;
        }

        return ContactEmail::query()
            ->with('contact.relations')
            ->where('email', $email)
            ->first()
            ?->contact;
    }

    private function clientIdForContact(?Contact $contact): ?int
    {
        return $contact?->relations
            ->first(fn ($relation): bool => str_contains((string) $relation->related_type, 'Client'))
            ?->related_id;
    }

    private function normalizeSignalType(mixed $value): string
    {
        return str((string) $value)
            ->trim()
            ->lower()
            ->replace([' ', '-'], '_')
            ->toString();
    }

    private function queueFromRecipients(EmailMessage $message): ?TicketQueue
    {
        // Email addresses may be stored as plain strings or parsed address arrays depending on the source parser.
        $recipients = collect((array) $message->to_json)
            ->merge((array) $message->cc_json)
            ->map(fn ($recipient) => is_array($recipient) ? ($recipient['email'] ?? $recipient['address'] ?? '') : $recipient)
            ->filter()
            ->map(fn ($recipient) => strtolower((string) $recipient))
            ->values();

        if ($recipients->isEmpty()) {
            return null;
        }

        return TicketQueue::query()
            ->whereNotNull('email_address')
            ->get()
            ->first(fn (TicketQueue $queue) => $recipients->contains(strtolower((string) $queue->email_address)));
    }
}
