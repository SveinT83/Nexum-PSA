<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use App\Models\Tech\Work\Assets\AssetAlert;
use App\Modules\Integration\Models\RmmAlertOccurrence;
use App\Modules\Integration\Models\RmmAlertRule;
use App\Modules\Integration\Models\RmmAlertRuleExecution;
use App\Modules\Integration\Models\RmmAlertWorkItem;
use App\Modules\Integration\Support\RmmAlertProcessingLeaseLost;
use App\Modules\Signal\Actions\ProcessSignalRules;
use App\Modules\Signal\Actions\RecordSignal;
use App\Modules\Signal\Models\Signal;
use App\Modules\Task\Actions\RecordTaskSourceActivity;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Task\Models\Task;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Ticket\Actions\ReopenTicket;
use App\Modules\Ticket\Actions\StoreIdempotentTicketInternalNote;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ExecuteRmmAlertRuleAction
{
    public function __construct(
        private readonly RmmAlertAutomationActor $actors,
        private readonly StoreTicket $tickets,
        private readonly StoreIdempotentTicketInternalNote $ticketNotes,
        private readonly ReopenTicket $reopenTickets,
        private readonly StoreTask $tasks,
        private readonly RecordTaskSourceActivity $taskActivity,
        private readonly RecordSignal $signals,
        private readonly ProcessSignalRules $signalRules,
    ) {}

    /** @return array<string, mixed> */
    public function handle(
        RmmAlertOccurrence $occurrence,
        RmmAlertRule $rule,
        RmmAlertRuleExecution $execution,
        array $action,
        int $actionIndex,
    ): array {
        $type = (string) ($action['type'] ?? '');

        return match ($type) {
            'create_ticket' => $this->createTicket($occurrence, $rule, $execution, $action, $actionIndex),
            'create_task' => $this->createTask($occurrence, $rule, $execution, $action, $actionIndex),
            'reopen_ticket' => $this->reopenTicket($occurrence, $rule, $execution, $action, $actionIndex),
            'emit_signal' => $this->emitSignal($occurrence, $rule, $execution, $action, $actionIndex),
            'ignore' => $this->ignore($occurrence, $rule, $execution, $actionIndex),
            default => throw new InvalidArgumentException("Unsupported RMM action: {$type}."),
        };
    }

    /** @return array<string, mixed> */
    private function createTicket(
        RmmAlertOccurrence $occurrence,
        RmmAlertRule $rule,
        RmmAlertRuleExecution $execution,
        array $action,
        int $actionIndex,
    ): array {
        return DB::transaction(function () use ($occurrence, $rule, $execution, $action, $actionIndex): array {
            $alert = $this->lockAlert($occurrence);
            if ($existing = $this->existingActionLink($occurrence, $rule, $actionIndex)) {
                return $this->replayedResult($existing);
            }

            $ticket = $this->openLinkedTicket($occurrence);
            if (! $ticket) {
                $this->assertActiveRoutingReferences($action, [
                    'queue_id',
                    'ticket_type_id',
                    'priority_id',
                    'category_id',
                    'owner_id',
                ]);
            }
            $actor = $this->actors->resolve();
            $this->requirePermission($actor, 'ticket.create');
            $result = 'updated_existing';

            if ($ticket) {
                $this->addTicketOccurrenceNote($ticket, $occurrence, $rule, $actionIndex, $actor);
            } else {
                $asset = $alert->asset;
                $ticket = $this->tickets->handle([
                    'client_id' => $asset?->client_id,
                    'site_id' => $asset?->site_id,
                    'asset_id' => $asset?->id,
                    'owner_id' => $action['owner_id'] ?? null,
                    'queue_id' => $action['queue_id'] ?? null,
                    'ticket_type_id' => $action['ticket_type_id'] ?? null,
                    'priority_id' => $action['priority_id'] ?? null,
                    'category_id' => $action['category_id'] ?? null,
                    'channel' => 'rmm',
                    'subject' => $this->workTitle($action['subject'] ?? null, $occurrence),
                    'description' => $this->workDescription($action['description'] ?? null, $occurrence, $alert),
                    'metadata' => $this->targetMetadata($occurrence, $rule, $actionIndex),
                    'suppress_notifications' => true,
                ], $actor);
                $result = 'created';
            }

            $link = $this->createLink($occurrence, $rule, $execution, $actionIndex, 'create_ticket', $ticket, [
                'result' => $result,
                'ticket_key' => $ticket->ticket_key,
            ]);

            return [
                'type' => 'create_ticket',
                'status' => 'done',
                'result' => $result,
                'ticket_id' => $ticket->id,
                'ticket_key' => $ticket->ticket_key,
                'work_item_id' => $link->id,
            ];
        });
    }

    /** @return array<string, mixed> */
    private function createTask(
        RmmAlertOccurrence $occurrence,
        RmmAlertRule $rule,
        RmmAlertRuleExecution $execution,
        array $action,
        int $actionIndex,
    ): array {
        return DB::transaction(function () use ($occurrence, $rule, $execution, $action, $actionIndex): array {
            $alert = $this->lockAlert($occurrence);
            if ($existing = $this->existingActionLink($occurrence, $rule, $actionIndex)) {
                return $this->replayedResult($existing);
            }

            $task = $this->openLinkedTask($occurrence);
            if (! $task) {
                $this->assertActiveRoutingReferences($action, [
                    'queue_id',
                    'priority_id',
                    'category_id',
                    'assigned_to',
                ]);
            }
            $actor = $this->actors->resolve();
            $this->requirePermission($actor, 'task.create');
            $result = 'updated_existing';

            if ($task) {
                $this->taskActivity->handle(
                    $task,
                    $this->occurrenceSummary($occurrence),
                    $actor,
                    $this->targetMetadata($occurrence, $rule, $actionIndex),
                );
            } else {
                $asset = $alert->asset;
                $dueMinutes = max(0, (int) ($action['due_minutes_from_now'] ?? 0));
                $task = $this->tasks->handle([
                    'title' => $this->workTitle($action['title'] ?? null, $occurrence),
                    'description' => $this->workDescription($action['description'] ?? null, $occurrence, $alert),
                    'client_id' => $asset?->client_id,
                    'site_id' => $asset?->site_id,
                    'queue_id' => $action['queue_id'] ?? null,
                    'priority_id' => $action['priority_id'] ?? null,
                    'category_id' => $action['category_id'] ?? null,
                    'assigned_to' => $action['assigned_to'] ?? null,
                    'due_at' => $dueMinutes > 0 ? now()->addMinutes($dueMinutes) : null,
                    'estimated_minutes' => $action['estimated_minutes'] ?? null,
                    'source_type' => 'rmm_alert',
                    'source_id' => $alert->id,
                    'metadata' => $this->targetMetadata($occurrence, $rule, $actionIndex),
                ], $actor, $asset?->client ?: $actor);
                $result = 'created';
            }

            $link = $this->createLink($occurrence, $rule, $execution, $actionIndex, 'create_task', $task, [
                'result' => $result,
            ]);

            return [
                'type' => 'create_task',
                'status' => 'done',
                'result' => $result,
                'task_id' => $task->id,
                'work_item_id' => $link->id,
            ];
        });
    }

    /** @return array<string, mixed> */
    private function reopenTicket(
        RmmAlertOccurrence $occurrence,
        RmmAlertRule $rule,
        RmmAlertRuleExecution $execution,
        array $action,
        int $actionIndex,
    ): array {
        return DB::transaction(function () use ($occurrence, $rule, $execution, $action, $actionIndex): array {
            $this->lockAlert($occurrence);
            if ($existing = $this->existingActionLink($occurrence, $rule, $actionIndex)) {
                return $this->replayedResult($existing);
            }

            $actor = $this->actors->resolve();
            $ticket = $this->latestLinkedTicket($occurrence);
            $result = 'no_linked_ticket';

            if ($open = $this->openLinkedTicket($occurrence)) {
                $ticket = $open;
                $result = 'already_open';
                $this->addTicketOccurrenceNote($ticket, $occurrence, $rule, $actionIndex, $actor);
            } elseif ($ticket) {
                $ticket->loadMissing('status');
                if ($ticket->status?->is_closed || $ticket->closed_at) {
                    $this->assertActiveRoutingReferences($action, ['reopen_status_id']);
                    $status = TicketStatus::query()->findOrFail((int) $action['reopen_status_id']);
                    $ticket = $this->reopenTickets->handle(
                        $ticket,
                        $status,
                        $actor,
                        $this->actionKey($occurrence, $rule, $actionIndex, 'reopen'),
                        notificationsEnabled: false,
                    );
                    $result = 'reopened';
                }
                $this->addTicketOccurrenceNote($ticket, $occurrence, $rule, $actionIndex, $actor);
            }

            $link = $this->createLink($occurrence, $rule, $execution, $actionIndex, 'reopen_ticket', $ticket, [
                'result' => $result,
                'ticket_key' => $ticket?->ticket_key,
            ]);

            return [
                'type' => 'reopen_ticket',
                'status' => $ticket ? 'done' : 'skipped',
                'result' => $result,
                'ticket_id' => $ticket?->id,
                'ticket_key' => $ticket?->ticket_key,
                'work_item_id' => $link->id,
            ];
        });
    }

    /** @return array<string, mixed> */
    private function emitSignal(
        RmmAlertOccurrence $occurrence,
        RmmAlertRule $rule,
        RmmAlertRuleExecution $execution,
        array $action,
        int $actionIndex,
    ): array {
        $link = DB::transaction(function () use ($occurrence, $rule, $execution, $action, $actionIndex): RmmAlertWorkItem {
            $alert = $this->lockAlert($occurrence);
            if ($existing = $this->existingActionLink($occurrence, $rule, $actionIndex)) {
                return $existing;
            }

            $asset = $alert->asset;
            $signal = $this->signals->handle([
                'source_domain' => 'rmm',
                'source_type' => $alert->getMorphClass(),
                'source_id' => $alert->id,
                'subject_type' => $asset?->getMorphClass(),
                'subject_id' => $asset?->id,
                'client_id' => $asset?->client_id,
                'signal_type' => (string) $action['signal_type'],
                'severity' => $action['severity'] ?? $occurrence->severity,
                'summary' => Str::limit((string) ($action['summary'] ?? $occurrence->title), 500, ''),
                'payload' => $this->targetMetadata($occurrence, $rule, $actionIndex),
                'occurred_at' => $occurrence->occurred_at,
            ], false);

            return $this->createLink($occurrence, $rule, $execution, $actionIndex, 'emit_signal', $signal, [
                'result' => 'created',
                'signal_rules_status' => 'pending',
            ]);
        });

        /** @var Signal|null $signal */
        $signal = $link->target;
        if ($signal && data_get($link->metadata, 'signal_rules_status') !== 'processed') {
            try {
                DB::transaction(function () use ($occurrence, $signal, $link): void {
                    $this->lockAlert($occurrence);
                    $this->signalRules->handle($signal);
                    $link->forceFill(['metadata' => array_merge($link->metadata ?? [], [
                        'signal_rules_status' => 'processed',
                    ])])->save();
                });
            } catch (\Throwable $exception) {
                $link->forceFill(['metadata' => array_merge($link->metadata ?? [], [
                    'signal_rules_status' => 'failed',
                ])])->save();
                throw $exception;
            }
        }

        return [
            'type' => 'emit_signal',
            'status' => 'done',
            'result' => data_get($link->metadata, 'result', 'replayed'),
            'signal_id' => $signal?->id,
            'work_item_id' => $link->id,
        ];
    }

    /** @return array<string, mixed> */
    private function ignore(
        RmmAlertOccurrence $occurrence,
        RmmAlertRule $rule,
        RmmAlertRuleExecution $execution,
        int $actionIndex,
    ): array {
        $link = DB::transaction(function () use ($occurrence, $rule, $execution, $actionIndex): RmmAlertWorkItem {
            $this->lockAlert($occurrence);

            return $this->existingActionLink($occurrence, $rule, $actionIndex)
                ?: $this->createLink($occurrence, $rule, $execution, $actionIndex, 'ignore', null, [
                    'result' => 'ignored',
                ]);
        });

        return [
            'type' => 'ignore',
            'status' => 'done',
            'result' => 'ignored',
            'work_item_id' => $link->id,
            'stop_all' => true,
        ];
    }

    private function lockAlert(RmmAlertOccurrence $occurrence): AssetAlert
    {
        $alert = AssetAlert::query()->lockForUpdate()->findOrFail($occurrence->asset_alert_id);
        $lockedOccurrence = RmmAlertOccurrence::query()->lockForUpdate()->findOrFail($occurrence->id);
        if ($lockedOccurrence->processing_status !== 'processing'
            || $lockedOccurrence->processed_at !== null
            || ! is_string($lockedOccurrence->processing_token)
            || $lockedOccurrence->processing_token === ''
            || ! is_string($occurrence->processing_token)
            || ! hash_equals($lockedOccurrence->processing_token, $occurrence->processing_token)) {
            throw new RmmAlertProcessingLeaseLost('RMM processing lease is no longer active.');
        }

        $asset = Asset::query()
            ->with('client')
            ->lockForUpdate()
            ->findOrFail($alert->asset_id);
        $alert->setRelation('asset', $asset);
        $expected = [
            'asset_id' => data_get($occurrence->context, 'asset_id'),
            'client_id' => data_get($occurrence->context, 'client_id'),
            'site_id' => data_get($occurrence->context, 'site_id'),
        ];
        $actual = [
            'asset_id' => $alert->asset_id,
            'client_id' => $asset->client_id,
            'site_id' => $asset->site_id,
        ];
        if ($expected !== $actual) {
            throw new \RuntimeException('RMM occurrence context changed before target routing.');
        }

        return $alert;
    }

    private function requirePermission(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new AuthorizationException("Missing RMM automation permission: {$permission}.");
        }
    }

    /**
     * Rule references are valid when saved, but administrators can later
     * disable routing targets. Recheck and lock only the references used for
     * a new side effect so stale rules fail closed without blocking reuse of
     * an already-linked Ticket or Task.
     *
     * @param  list<string>  $fields
     */
    private function assertActiveRoutingReferences(array $action, array $fields): void
    {
        foreach ($fields as $field) {
            if (! isset($action[$field])) {
                continue;
            }

            $id = (int) $action[$field];
            $query = match ($field) {
                'queue_id' => TicketQueue::query()->whereKey($id)->where('is_active', true),
                'ticket_type_id' => TicketType::query()->whereKey($id)->where('is_active', true),
                'priority_id' => TicketPriority::query()->whereKey($id)->where('is_active', true),
                'category_id' => Category::query()->forTickets()->active()->whereKey($id),
                'owner_id', 'assigned_to' => User::query()
                    ->whereKey($id)
                    ->where('status', User::STATUS_ACTIVE)
                    ->where('is_system_actor', false),
                'reopen_status_id' => TicketStatus::query()
                    ->whereKey($id)
                    ->where('is_active', true)
                    ->where('is_closed', false),
                default => null,
            };

            if (! $query || ! $query->lockForUpdate()->first()) {
                throw new \RuntimeException("RMM routing reference is missing or inactive: {$field}.");
            }
        }
    }

    private function openLinkedTicket(RmmAlertOccurrence $occurrence): ?Ticket
    {
        return Ticket::query()
            ->whereIn('id', $this->linkedTargetIds($occurrence, new Ticket))
            ->where('client_id', data_get($occurrence->context, 'client_id'))
            ->where('site_id', data_get($occurrence->context, 'site_id'))
            ->where('asset_id', data_get($occurrence->context, 'asset_id'))
            ->whereHas('status', fn ($query) => $query->where('is_closed', false))
            ->whereNull('closed_at')
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }

    private function latestLinkedTicket(RmmAlertOccurrence $occurrence): ?Ticket
    {
        return Ticket::query()
            ->whereIn('id', $this->linkedTargetIds($occurrence, new Ticket))
            ->where('client_id', data_get($occurrence->context, 'client_id'))
            ->where('site_id', data_get($occurrence->context, 'site_id'))
            ->where('asset_id', data_get($occurrence->context, 'asset_id'))
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }

    private function openLinkedTask(RmmAlertOccurrence $occurrence): ?Task
    {
        return Task::query()
            ->whereIn('id', $this->linkedTargetIds($occurrence, new Task))
            ->where('client_id', data_get($occurrence->context, 'client_id'))
            ->where('site_id', data_get($occurrence->context, 'site_id'))
            ->open()
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }

    /** @return array<int, int> */
    private function linkedTargetIds(RmmAlertOccurrence $occurrence, Model $target): array
    {
        return RmmAlertWorkItem::query()
            ->where('fingerprint', $occurrence->fingerprint)
            ->where('target_type', $target->getMorphClass())
            ->where('metadata->rmm_context_key', $this->contextKey($occurrence))
            ->whereNotNull('target_id')
            ->pluck('target_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();
    }

    private function addTicketOccurrenceNote(
        Ticket $ticket,
        RmmAlertOccurrence $occurrence,
        RmmAlertRule $rule,
        int $actionIndex,
        User $actor,
    ): void {
        $this->ticketNotes->handle($ticket, [
            'body' => $this->occurrenceSummary($occurrence),
            'metadata' => $this->targetMetadata($occurrence, $rule, $actionIndex),
            'idempotency_key' => $this->actionKey($occurrence, $rule, $actionIndex, 'ticket-note'),
            'suppress_notifications' => true,
        ], $actor);
    }

    private function occurrenceSummary(RmmAlertOccurrence $occurrence): string
    {
        return implode("\n", [
            'RMM alert occurrence #'.$occurrence->sequence.': '.$occurrence->title,
            'Severity: '.$occurrence->severity,
            'Provider: '.$occurrence->integration_type,
            'Fingerprint: '.$occurrence->fingerprint,
            'Observed: '.$occurrence->occurred_at?->toIso8601String(),
        ]);
    }

    private function workTitle(?string $prefix, RmmAlertOccurrence $occurrence): string
    {
        $prefix = trim((string) $prefix);

        return Str::limit(($prefix !== '' ? $prefix.': ' : '[RMM] ').$occurrence->title, 255, '');
    }

    private function workDescription(?string $intro, RmmAlertOccurrence $occurrence, AssetAlert $alert): string
    {
        $lines = array_filter([
            trim((string) $intro),
            $this->occurrenceSummary($occurrence),
            'Asset: '.($alert->asset?->hostname ?: $alert->asset?->name ?: '#'.$alert->asset_id),
        ]);

        return Str::limit(implode("\n\n", $lines), 4000, '');
    }

    /** @return array<string, mixed> */
    private function targetMetadata(RmmAlertOccurrence $occurrence, RmmAlertRule $rule, int $actionIndex): array
    {
        return [
            'created_from' => 'rmm_alert_rule',
            'rmm_alert_id' => $occurrence->asset_alert_id,
            'rmm_alert_occurrence_id' => $occurrence->id,
            'rmm_alert_occurrence_sequence' => $occurrence->sequence,
            'rmm_alert_fingerprint' => $occurrence->fingerprint,
            'rmm_integration_type' => $occurrence->integration_type,
            'rmm_rule_key' => $rule->rule_key,
            'rmm_rule_revision' => $rule->revision,
            'rmm_action_index' => $actionIndex,
            'rmm_asset_id' => data_get($occurrence->context, 'asset_id'),
            'rmm_client_id' => data_get($occurrence->context, 'client_id'),
            'rmm_site_id' => data_get($occurrence->context, 'site_id'),
            'rmm_context_key' => $this->contextKey($occurrence),
        ];
    }

    private function existingActionLink(RmmAlertOccurrence $occurrence, RmmAlertRule $rule, int $actionIndex): ?RmmAlertWorkItem
    {
        return RmmAlertWorkItem::query()
            ->where('rmm_alert_occurrence_id', $occurrence->id)
            ->where('rule_key', $rule->rule_key)
            ->where('action_index', $actionIndex)
            ->first();
    }

    private function createLink(
        RmmAlertOccurrence $occurrence,
        RmmAlertRule $rule,
        RmmAlertRuleExecution $execution,
        int $actionIndex,
        string $actionType,
        ?Model $target,
        array $metadata,
    ): RmmAlertWorkItem {
        return RmmAlertWorkItem::query()->create([
            'rmm_alert_occurrence_id' => $occurrence->id,
            'asset_alert_id' => $occurrence->asset_alert_id,
            'rmm_alert_rule_execution_id' => $execution->id,
            'rule_key' => $rule->rule_key,
            'action_index' => $actionIndex,
            'action_type' => $actionType,
            'fingerprint' => $occurrence->fingerprint,
            'target_type' => $target?->getMorphClass(),
            'target_id' => $target?->getKey(),
            'metadata' => [
                ...$this->targetMetadata($occurrence, $rule, $actionIndex),
                ...$metadata,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function replayedResult(RmmAlertWorkItem $link): array
    {
        return [
            'type' => $link->action_type,
            'status' => 'skipped',
            'result' => data_get($link->metadata, 'result', 'replayed'),
            'target_type' => $link->target_type,
            'target_id' => $link->target_id,
            'work_item_id' => $link->id,
        ];
    }

    private function actionKey(
        RmmAlertOccurrence $occurrence,
        RmmAlertRule $rule,
        int $actionIndex,
        string $suffix,
    ): string {
        return implode(':', ['rmm', $occurrence->id, $rule->rule_key, $actionIndex, $suffix]);
    }

    private function contextKey(RmmAlertOccurrence $occurrence): string
    {
        return hash('sha256', implode('|', [
            (string) data_get($occurrence->context, 'asset_id', '-'),
            (string) data_get($occurrence->context, 'client_id', '-'),
            (string) data_get($occurrence->context, 'site_id', '-'),
        ]));
    }
}
