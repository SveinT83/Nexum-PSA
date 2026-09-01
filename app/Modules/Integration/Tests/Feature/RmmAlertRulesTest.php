<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use App\Models\Tech\Work\Assets\AssetAlert;
use App\Modules\Integration\Actions\ExecuteRmmAlertRuleAction;
use App\Modules\Integration\Actions\ProcessRmmAlertRules;
use App\Modules\Integration\Actions\RecordRmmAlertObservation;
use App\Modules\Integration\Actions\RmmAlertAutomationActor;
use App\Modules\Integration\Actions\SaveRmmAlertRule;
use App\Modules\Integration\Models\RmmAlertOccurrence;
use App\Modules\Integration\Models\RmmAlertRule;
use App\Modules\Integration\Models\RmmAlertRuleExecution;
use App\Modules\Integration\Models\RmmAlertWorkItem;
use App\Modules\Integration\Support\RmmAlertProcessingLeaseLost;
use App\Modules\Integration\Support\RmmAlertRuleDefinition;
use App\Modules\Integration\Support\RmmAlertRuleMatcher;
use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRule;
use App\Modules\Signal\Models\SignalRuleExecution;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskActivity;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RmmAlertRulesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function healthy_observations_are_no_ops_and_alert_lifecycle_creates_only_activation_occurrences(): void
    {
        [$client, $site, $asset] = $this->assetContext();
        $observations = app(RecordRmmAlertObservation::class);

        $this->assertNull($observations->handle($asset, $this->observation(['active' => false])));
        $this->assertDatabaseCount('asset_alerts', 0);

        $alert = $observations->handle($asset, $this->observation([
            'severity' => 'critical',
            'provider_context' => [
                'check_type' => 'backup',
                'raw_severity' => 'critical',
                'token' => 'must-not-be-stored',
            ],
        ]));

        $this->assertNotNull($alert);
        $this->assertSame('active', $alert->status);
        $this->assertSame('critical', $alert->severity);
        $this->assertDatabaseCount('rmm_alert_occurrences', 1);
        $first = RmmAlertOccurrence::query()->firstOrFail();
        $this->assertSame('triggered', $first->event_type);
        $this->assertSame(1, $first->sequence);
        $this->assertSame($asset->id, data_get($first->context, 'asset_id'));
        $this->assertSame($client->id, data_get($first->context, 'client_id'));
        $this->assertSame($site->id, data_get($first->context, 'site_id'));
        $this->assertSame('backup', data_get($first->context, 'provider.check_type'));
        $this->assertNull(data_get($first->context, 'provider.token'));

        $observations->handle($asset, $this->observation(['title' => 'Backup still failing']));
        $this->assertDatabaseCount('rmm_alert_occurrences', 1);

        $observations->handle($asset, $this->observation(['active' => false]));
        $this->assertNotNull($first->fresh()->resolved_at);
        $this->assertDatabaseCount('rmm_alert_occurrences', 1);

        $reopened = $observations->handle($asset, $this->observation());
        $this->assertSame('active', $reopened?->status);
        $this->assertDatabaseCount('rmm_alert_occurrences', 2);
        $second = RmmAlertOccurrence::query()->latest('sequence')->firstOrFail();
        $this->assertSame('reopened', $second->event_type);
        $this->assertSame(2, $second->sequence);
    }

    #[Test]
    public function matcher_uses_immutable_occurrence_context_and_all_conditions_are_implicit_and(): void
    {
        [$originalClient, , $asset] = $this->assetContext();
        app(RecordRmmAlertObservation::class)->handle($asset, $this->observation(['severity' => 'critical']));
        $occurrence = RmmAlertOccurrence::query()->firstOrFail();

        $newClient = Client::factory()->create();
        $asset->update(['client_id' => $newClient->id, 'site_id' => null]);

        $conditions = [
            'subject_contains' => 'backup',
            'severities' => ['critical'],
            'asset_id' => $asset->id,
            'client_id' => $originalClient->id,
            'fingerprint' => $occurrence->fingerprint,
            'integration_types' => ['tactical'],
        ];
        $evaluation = app(RmmAlertRuleMatcher::class)->evaluate($occurrence, $conditions);

        $this->assertTrue($evaluation['matched']);
        $this->assertCount(6, $evaluation['results']);
        $this->assertFalse(app(RmmAlertRuleMatcher::class)->evaluate(
            $occurrence,
            [...$conditions, 'severities' => ['warning']],
        )['matched']);
    }

    #[Test]
    public function definition_rejects_unknown_or_invalid_conditions_instead_of_broadening_a_rule(): void
    {
        $definitions = app(RmmAlertRuleDefinition::class);

        foreach ([
            ['subject_contains' => 'backup', 'severities' => ['urgent']],
            ['subject_contains' => 'backup', 'integration_types' => ['unknown-provider']],
            ['subject_contains' => 'backup', 'client_id' => 0],
            ['subject_contains' => 'backup', 'typo_condition' => 'ignored'],
        ] as $conditions) {
            try {
                $definitions->normalizeConditions($conditions);
                $this->fail('Invalid RMM condition was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    #[Test]
    public function ignore_is_audited_and_stops_lower_priority_rules(): void
    {
        [, , $asset] = $this->assetContext();
        $this->rule(10, [['type' => 'ignore']]);
        $this->rule(20, [['type' => 'create_ticket']]);

        app(RecordRmmAlertObservation::class)->handle($asset, $this->observation());

        $this->assertDatabaseCount('rmm_alert_rule_executions', 1);
        $this->assertDatabaseHas('rmm_alert_rule_executions', ['status' => 'ignored', 'matched' => true]);
        $this->assertDatabaseHas('rmm_alert_work_items', ['action_type' => 'ignore', 'target_id' => null]);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('signals', 0);
    }

    #[Test]
    public function ticket_and_task_actions_create_once_then_reuse_open_work_on_recurrence(): void
    {
        Queue::fake();
        Notification::fake();
        [$client, $site, $asset] = $this->assetContext();
        $owner = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'is_system_actor' => false,
        ]);
        $rule = $this->rule(10, [
            ['type' => 'create_ticket', 'subject' => 'Monitor', 'owner_id' => $owner->id],
            ['type' => 'create_task', 'title' => 'Investigate'],
        ]);
        $observations = app(RecordRmmAlertObservation::class);

        $observations->handle($asset, $this->observation());

        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('rmm_alert_work_items', 2);
        $ticket = Ticket::query()->firstOrFail();
        $task = Task::query()->firstOrFail();
        $actor = User::query()->where('system_actor_key', RmmAlertAutomationActor::KEY)->firstOrFail();

        $this->assertSame('rmm', $ticket->channel);
        $this->assertSame($client->id, $ticket->client_id);
        $this->assertSame($site->id, $ticket->site_id);
        $this->assertSame($asset->id, $ticket->asset_id);
        $this->assertSame($owner->id, $ticket->owner_id);
        $this->assertSame('rmm_alert', $task->source_type);
        $this->assertSame($client->id, $task->client_id);
        $this->assertSame($site->id, $task->site_id);
        $this->assertSame($actor->id, $task->created_by);
        $this->assertTrue($actor->isSystemActor());
        $this->assertTrue($actor->isDisabled());
        $this->assertEqualsCanonicalizing([
            'task.create',
            'task.source_update',
            'ticket.create',
            'ticket.note_internal',
            'ticket.reopen',
        ], $actor->permissions->pluck('name')->all());

        $observations->handle($asset, $this->observation(['title' => 'Unchanged heartbeat']));
        $this->assertDatabaseCount('rmm_alert_occurrences', 1);
        $this->assertDatabaseCount('rmm_alert_work_items', 2);

        $observations->handle($asset, $this->observation(['active' => false]));
        $observations->handle($asset, $this->observation(['title' => 'Backup failed again']));

        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('rmm_alert_occurrences', 2);
        $this->assertDatabaseCount('rmm_alert_work_items', 4);
        $this->assertSame(1, TicketMessage::query()->whereNotNull('idempotency_key')->count());
        $this->assertSame(1, TaskActivity::query()->where('type', 'source_update')->count());
        $this->assertSame(2, RmmAlertRuleExecution::query()->where('rmm_alert_rule_id', $rule->id)->count());
        $this->assertSame([$ticket->id], RmmAlertWorkItem::query()
            ->where('action_type', 'create_ticket')->pluck('target_id')->unique()->values()->all());
        $this->assertSame([$task->id], RmmAlertWorkItem::query()
            ->where('action_type', 'create_task')->pluck('target_id')->unique()->values()->all());
        Notification::assertNothingSent();
    }

    #[Test]
    public function emit_signal_hands_off_once_and_normal_signal_rules_continue(): void
    {
        [, , $asset] = $this->assetContext();
        SignalRule::query()->create([
            'name' => 'Observe RMM signal',
            'is_active' => true,
            'priority' => 10,
            'stop_processing' => false,
            'conditions' => ['source_domain' => ['rmm'], 'signal_type' => ['rmm.backup_failed']],
            'actions' => [['type' => 'test_noop']],
        ]);
        $this->rule(10, [[
            'type' => 'emit_signal',
            'signal_type' => 'rmm.backup_failed',
            'summary' => 'Backup failure from RMM',
        ]]);

        app(RecordRmmAlertObservation::class)->handle($asset, $this->observation());
        $occurrence = RmmAlertOccurrence::query()->firstOrFail();

        $this->assertDatabaseCount('signals', 1);
        $this->assertDatabaseCount('signal_rule_executions', 1);
        $this->assertDatabaseCount('rmm_alert_work_items', 1);
        $signal = Signal::query()->firstOrFail();
        $this->assertSame('rmm', $signal->source_domain);
        $this->assertSame('rmm.backup_failed', $signal->signal_type);
        $this->assertSame('executed', SignalRuleExecution::query()->firstOrFail()->status);
        $this->assertSame('processed', data_get(RmmAlertWorkItem::query()->firstOrFail()->metadata, 'signal_rules_status'));

        $this->assertSame(0, app(ProcessRmmAlertRules::class)->handle($occurrence));
        $this->assertDatabaseCount('signals', 1);
        $this->assertDatabaseCount('signal_rule_executions', 1);
    }

    #[Test]
    public function emit_signal_webhook_waits_for_commit_and_is_discarded_on_rollback(): void
    {
        config()->set('queue.default', 'sync');
        Http::fake([
            'webhook.example.test/*' => Http::response('', 204),
        ]);

        SignalRule::query()->create([
            'name' => 'Deliver committed RMM signal',
            'is_active' => true,
            'priority' => 10,
            'stop_processing' => false,
            'conditions' => ['source_domain' => ['rmm'], 'signal_type' => ['rmm.backup_failed']],
            'actions' => [['type' => 'webhook', 'url' => 'https://webhook.example.test/rmm']],
        ]);
        $this->rule(10, [[
            'type' => 'emit_signal',
            'signal_type' => 'rmm.backup_failed',
        ]]);
        [, , $committedAsset] = $this->assetContext();
        $baselineTransactionLevel = DB::transactionLevel();

        DB::transaction(function () use ($committedAsset, $baselineTransactionLevel): void {
            app(RecordRmmAlertObservation::class)->handle($committedAsset, $this->observation());

            $this->assertSame($baselineTransactionLevel + 1, DB::transactionLevel());
            Http::assertNothingSent();
        });

        Http::assertSentCount(1);
        $this->assertDatabaseCount('signals', 1);
        $this->assertDatabaseCount('signal_webhook_deliveries', 1);

        [, , $rolledBackAsset] = $this->assetContext();
        try {
            DB::transaction(function () use ($rolledBackAsset): void {
                app(RecordRmmAlertObservation::class)->handle($rolledBackAsset, $this->observation([
                    'external_check_id' => 'check-rollback',
                    'fingerprint' => 'tactical:rmm-rollback:check-rollback',
                ]));

                Http::assertSentCount(1);

                throw new \RuntimeException('rollback');
            });
            $this->fail('The rollback probe did not throw.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        Http::assertSentCount(1);
        $this->assertDatabaseCount('signals', 1);
        $this->assertDatabaseCount('signal_webhook_deliveries', 1);
    }

    #[Test]
    public function changed_asset_ownership_fails_closed_with_a_sanitized_audit_error(): void
    {
        [$client, $site, $asset] = $this->assetContext();
        $this->rule(10, [['type' => 'create_ticket']]);
        $alert = AssetAlert::query()->create([
            'asset_id' => $asset->id,
            'integration_type' => 'tactical',
            'fingerprint' => 'tactical:'.$asset->id.':ownership-drift',
            'title' => 'Ownership drift test',
            'status' => 'active',
            'severity' => 'warning',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $occurrence = RmmAlertOccurrence::query()->create([
            'asset_alert_id' => $alert->id,
            'sequence' => 1,
            'event_type' => 'triggered',
            'integration_type' => 'tactical',
            'fingerprint' => $alert->fingerprint,
            'severity' => 'warning',
            'title' => $alert->title,
            'context' => ['asset_id' => $asset->id, 'client_id' => $client->id, 'site_id' => $site->id],
            'occurred_at' => now(),
            'processing_status' => 'pending',
        ]);
        $otherClient = Client::factory()->create();
        $asset->update(['client_id' => $otherClient->id, 'site_id' => null]);

        app(ProcessRmmAlertRules::class)->handle($occurrence);

        $execution = RmmAlertRuleExecution::query()->firstOrFail();
        $this->assertSame('failed', $execution->status);
        $this->assertSame('RMM processing failed (RuntimeException).', $execution->error);
        $this->assertStringNotContainsString('context changed', $execution->error);
        $this->assertSame('completed_with_failures', $occurrence->fresh()->processing_status);
        $this->assertDatabaseCount('tickets', 0);
    }

    #[Test]
    public function inactive_saved_routing_references_fail_closed_and_allow_a_fallback_rule(): void
    {
        [, , $asset] = $this->assetContext();
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $category = Category::query()->create([
            'name' => 'RMM Routing',
            'type' => Category::TYPE_TICKET,
            'is_active' => true,
        ]);
        $owner = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'is_system_actor' => false,
        ]);
        $assignee = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'is_system_actor' => false,
        ]);

        $actions = [
            ['type' => 'create_ticket', 'queue_id' => $defaults['queue']->id],
            ['type' => 'create_ticket', 'ticket_type_id' => $defaults['type']->id],
            ['type' => 'create_ticket', 'priority_id' => $defaults['priority']->id],
            ['type' => 'create_ticket', 'category_id' => $category->id],
            ['type' => 'create_ticket', 'owner_id' => $owner->id],
            ['type' => 'create_task', 'queue_id' => $defaults['queue']->id],
            ['type' => 'create_task', 'priority_id' => $defaults['priority']->id],
            ['type' => 'create_task', 'category_id' => $category->id],
            ['type' => 'create_task', 'assigned_to' => $assignee->id],
        ];
        foreach ($actions as $index => $action) {
            app(SaveRmmAlertRule::class)->handle([
                'name' => 'Stale routing reference '.($index + 1),
                'is_active' => true,
                'priority' => ($index + 1) * 10,
                'stop_processing' => false,
                'conditions' => ['integration_types' => ['tactical']],
                'actions' => [$action],
            ], null, null);
        }
        $this->rule(100, [['type' => 'ignore']]);

        $defaults['queue']->update(['is_active' => false]);
        $defaults['type']->update(['is_active' => false]);
        $defaults['priority']->update(['is_active' => false]);
        $category->update(['is_active' => false]);
        $owner->update(['status' => User::STATUS_DISABLED]);
        $assignee->update(['is_system_actor' => true]);

        app(RecordRmmAlertObservation::class)->handle($asset, $this->observation());

        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('rmm_alert_work_items', 1);
        $this->assertDatabaseHas('rmm_alert_work_items', ['action_type' => 'ignore']);
        $this->assertSame(
            [...array_fill(0, 9, 'failed'), 'ignored'],
            RmmAlertRuleExecution::query()->orderBy('id')->pluck('status')->all(),
        );
        $this->assertSame(
            ['RMM processing failed (RuntimeException).'],
            RmmAlertRuleExecution::query()->where('status', 'failed')->pluck('error')->unique()->values()->all(),
        );
        $this->assertSame('completed_with_failures', RmmAlertOccurrence::query()->firstOrFail()->processing_status);
    }

    #[Test]
    public function a_no_op_reopen_does_not_suppress_a_lower_create_ticket_fallback(): void
    {
        [, , $asset] = $this->assetContext();
        app(\App\Modules\Ticket\Actions\EnsureTicketDefaults::class)->handle();
        $inProgress = TicketStatus::query()->where('slug', 'in-progress')->firstOrFail();
        $reopen = $this->rule(10, [[
            'type' => 'reopen_ticket',
            'reopen_status_id' => $inProgress->id,
        ]]);
        $reopen->update(['stop_processing' => true]);
        $this->rule(20, [['type' => 'create_ticket']]);

        app(RecordRmmAlertObservation::class)->handle($asset, $this->observation());

        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('rmm_alert_rule_executions', 2);
        $first = RmmAlertRuleExecution::query()->oldest('id')->firstOrFail();
        $this->assertSame('skipped', data_get($first->action_results, '0.status'));
        $this->assertSame('no_linked_ticket', data_get($first->action_results, '0.result'));
    }

    #[Test]
    public function a_successful_stop_rule_prevents_lower_rules(): void
    {
        [, , $asset] = $this->assetContext();
        $first = $this->rule(10, [['type' => 'create_ticket']]);
        $first->update(['stop_processing' => true]);
        $this->rule(20, [['type' => 'create_task']]);

        app(RecordRmmAlertObservation::class)->handle($asset, $this->observation());

        $this->assertDatabaseCount('rmm_alert_rule_executions', 1);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('tasks', 0);
    }

    #[Test]
    public function an_action_failure_marks_later_actions_not_run_and_allows_lower_rules(): void
    {
        [, , $asset] = $this->assetContext();
        $failedRule = $this->rule(10, [
            ['type' => 'create_ticket', 'queue_id' => 999999],
            ['type' => 'create_task'],
        ]);
        $lowerRule = $this->rule(20, [['type' => 'ignore']]);

        app(RecordRmmAlertObservation::class)->handle($asset, $this->observation());

        $failed = RmmAlertRuleExecution::query()->where('rmm_alert_rule_id', $failedRule->id)->firstOrFail();
        $lower = RmmAlertRuleExecution::query()->where('rmm_alert_rule_id', $lowerRule->id)->firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('failed', data_get($failed->action_results, '0.status'));
        $this->assertSame('not_run', data_get($failed->action_results, '1.status'));
        $this->assertSame('ignored', $lower->status);
        $this->assertSame('completed_with_failures', RmmAlertOccurrence::query()->firstOrFail()->processing_status);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    #[Test]
    public function stale_pre_execution_work_retries_but_resolved_or_started_work_is_terminalized_without_replay(): void
    {
        [, , $asset] = $this->assetContext();
        $rule = $this->rule(10, [['type' => 'ignore']]);
        $preAlert = AssetAlert::query()->create([
            'asset_id' => $asset->id,
            'integration_type' => 'tactical',
            'fingerprint' => 'tactical:rmm-01:pre-execution',
            'title' => 'Pre-execution interruption',
            'status' => 'active',
            'severity' => 'warning',
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now()->subHour(),
        ]);
        $preOccurrence = RmmAlertOccurrence::query()->create([
            'asset_alert_id' => $preAlert->id,
            'sequence' => 1,
            'event_type' => 'triggered',
            'integration_type' => 'tactical',
            'fingerprint' => $preAlert->fingerprint,
            'severity' => 'warning',
            'title' => $preAlert->title,
            'context' => ['asset_id' => $asset->id, 'client_id' => $asset->client_id, 'site_id' => $asset->site_id],
            'occurred_at' => now()->subHour(),
            'processing_status' => 'processing',
            'processing_started_at' => now()->subMinutes(16),
        ]);

        app(RecordRmmAlertObservation::class)->handle($asset, $this->observation([
            'fingerprint' => $preAlert->fingerprint,
            'title' => $preAlert->title,
        ]));
        $this->assertSame('completed', $preOccurrence->fresh()->processing_status);
        $this->assertDatabaseHas('rmm_alert_rule_executions', [
            'rmm_alert_occurrence_id' => $preOccurrence->id,
            'status' => 'ignored',
        ]);

        $postAlert = AssetAlert::query()->create([
            'asset_id' => $asset->id,
            'integration_type' => 'tactical',
            'fingerprint' => 'tactical:rmm-01:started-execution',
            'title' => 'Started execution interruption',
            'status' => 'active',
            'severity' => 'warning',
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now()->subHour(),
        ]);
        $postOccurrence = RmmAlertOccurrence::query()->create([
            'asset_alert_id' => $postAlert->id,
            'sequence' => 1,
            'event_type' => 'triggered',
            'integration_type' => 'tactical',
            'fingerprint' => $postAlert->fingerprint,
            'severity' => 'warning',
            'title' => $postAlert->title,
            'context' => ['asset_id' => $asset->id, 'client_id' => $asset->client_id, 'site_id' => $asset->site_id],
            'occurred_at' => now()->subHour(),
            'processing_status' => 'processing',
            'processing_started_at' => now()->subMinutes(16),
        ]);
        $started = RmmAlertRuleExecution::query()->create([
            'rmm_alert_occurrence_id' => $postOccurrence->id,
            'rmm_alert_rule_id' => $rule->id,
            'rule_key' => $rule->rule_key,
            'rule_revision' => $rule->revision,
            'rule_name' => $rule->name,
            'matched' => true,
            'status' => 'evaluating',
            'rule_snapshot' => ['conditions' => $rule->conditions, 'actions' => $rule->actions],
            'condition_results' => [],
            'started_at' => now()->subMinutes(16),
        ]);

        app(RecordRmmAlertObservation::class)->handle($asset, $this->observation([
            'fingerprint' => $postAlert->fingerprint,
            'title' => $postAlert->title,
            'active' => false,
        ]));

        $this->assertSame('resolved', $postAlert->fresh()->status);
        $this->assertSame('failed', $started->fresh()->status);
        $this->assertSame('completed_with_failures', $postOccurrence->fresh()->processing_status);
        $this->assertNotNull($postOccurrence->fresh()->processed_at);
        $this->assertNotNull($postOccurrence->fresh()->resolved_at);
        $this->assertDatabaseCount('rmm_alert_work_items', 1);

        $unstartedAlert = AssetAlert::query()->create([
            'asset_id' => $asset->id,
            'integration_type' => 'tactical',
            'fingerprint' => 'tactical:rmm-01:resolved-before-execution',
            'title' => 'Resolved before execution',
            'status' => 'active',
            'severity' => 'warning',
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now()->subHour(),
        ]);
        $unstartedOccurrence = RmmAlertOccurrence::query()->create([
            'asset_alert_id' => $unstartedAlert->id,
            'sequence' => 1,
            'event_type' => 'triggered',
            'integration_type' => 'tactical',
            'fingerprint' => $unstartedAlert->fingerprint,
            'severity' => 'warning',
            'title' => $unstartedAlert->title,
            'context' => ['asset_id' => $asset->id, 'client_id' => $asset->client_id, 'site_id' => $asset->site_id],
            'occurred_at' => now()->subHour(),
            'processing_status' => 'pending',
            'processing_started_at' => null,
        ]);

        app(RecordRmmAlertObservation::class)->handle($asset, $this->observation([
            'fingerprint' => $unstartedAlert->fingerprint,
            'title' => $unstartedAlert->title,
            'active' => false,
        ]));

        $this->assertSame('resolved', $unstartedAlert->fresh()->status);
        $this->assertSame('failed', $unstartedOccurrence->fresh()->processing_status);
        $this->assertNotNull($unstartedOccurrence->fresh()->processed_at);
        $this->assertNotNull($unstartedOccurrence->fresh()->resolved_at);
        $this->assertDatabaseMissing('rmm_alert_rule_executions', [
            'rmm_alert_occurrence_id' => $unstartedOccurrence->id,
        ]);

        $partialAlert = AssetAlert::query()->create([
            'asset_id' => $asset->id,
            'integration_type' => 'tactical',
            'fingerprint' => 'tactical:rmm-01:partial-rule-set',
            'title' => 'Partial rule evaluation',
            'status' => 'active',
            'severity' => 'warning',
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now()->subHour(),
        ]);
        $partialOccurrence = RmmAlertOccurrence::query()->create([
            'asset_alert_id' => $partialAlert->id,
            'sequence' => 1,
            'event_type' => 'triggered',
            'integration_type' => 'tactical',
            'fingerprint' => $partialAlert->fingerprint,
            'severity' => 'warning',
            'title' => $partialAlert->title,
            'context' => ['asset_id' => $asset->id, 'client_id' => $asset->client_id, 'site_id' => $asset->site_id],
            'occurred_at' => now()->subHour(),
            'processing_status' => 'processing',
            'processing_started_at' => now()->subMinutes(16),
            'processing_token' => '11111111-1111-4111-8111-111111111111',
        ]);
        RmmAlertRuleExecution::query()->create([
            'rmm_alert_occurrence_id' => $partialOccurrence->id,
            'rmm_alert_rule_id' => $rule->id,
            'rule_key' => $rule->rule_key,
            'rule_revision' => $rule->revision,
            'rule_name' => $rule->name,
            'matched' => false,
            'status' => 'not_matched',
            'rule_snapshot' => ['conditions' => $rule->conditions, 'actions' => $rule->actions],
            'condition_results' => [],
            'started_at' => now()->subMinutes(16),
            'completed_at' => now()->subMinutes(16),
        ]);

        app(RecordRmmAlertObservation::class)->handle($asset, $this->observation([
            'fingerprint' => $partialAlert->fingerprint,
            'title' => $partialAlert->title,
            'active' => false,
        ]));

        $this->assertSame('completed_with_failures', $partialOccurrence->fresh()->processing_status);
        $this->assertNotNull($partialOccurrence->fresh()->processed_at);
        $this->assertNull($partialOccurrence->fresh()->processing_token);
    }

    #[Test]
    public function a_lost_processing_lease_blocks_late_target_side_effects(): void
    {
        [, , $asset] = $this->assetContext();
        $rule = $this->rule(10, [['type' => 'create_ticket']]);
        $alert = AssetAlert::query()->create([
            'asset_id' => $asset->id,
            'integration_type' => 'tactical',
            'fingerprint' => 'tactical:rmm-01:lost-lease',
            'title' => 'Lost lease',
            'status' => 'active',
            'severity' => 'warning',
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now()->subHour(),
        ]);
        $occurrence = RmmAlertOccurrence::query()->create([
            'asset_alert_id' => $alert->id,
            'sequence' => 1,
            'event_type' => 'triggered',
            'integration_type' => 'tactical',
            'fingerprint' => $alert->fingerprint,
            'severity' => 'warning',
            'title' => $alert->title,
            'context' => ['asset_id' => $asset->id, 'client_id' => $asset->client_id, 'site_id' => $asset->site_id],
            'occurred_at' => now()->subHour(),
            'processing_status' => 'processing',
            'processing_started_at' => now()->subMinutes(16),
            'processing_token' => '11111111-1111-4111-8111-111111111111',
        ]);
        $staleLease = $occurrence->fresh();
        $execution = RmmAlertRuleExecution::query()->create([
            'rmm_alert_occurrence_id' => $occurrence->id,
            'rmm_alert_rule_id' => $rule->id,
            'rule_key' => $rule->rule_key,
            'rule_revision' => $rule->revision,
            'rule_name' => $rule->name,
            'matched' => true,
            'status' => 'evaluating',
            'rule_snapshot' => ['conditions' => $rule->conditions, 'actions' => $rule->actions],
            'condition_results' => [],
            'started_at' => now()->subMinutes(16),
            'completed_at' => null,
        ]);
        $occurrence->forceFill([
            'processing_token' => '22222222-2222-4222-8222-222222222222',
        ])->save();

        try {
            app(ExecuteRmmAlertRuleAction::class)->handle(
                $staleLease,
                $rule,
                $execution,
                ['type' => 'create_ticket'],
                0,
            );
            $this->fail('A worker without the active RMM processing lease performed a target action.');
        } catch (RmmAlertProcessingLeaseLost) {
            $this->assertDatabaseCount('tickets', 0);
            $this->assertDatabaseCount('rmm_alert_work_items', 0);
            $this->assertSame('evaluating', $execution->fresh()->status);
            $this->assertSame('processing', $occurrence->fresh()->processing_status);
            $this->assertSame(
                '22222222-2222-4222-8222-222222222222',
                $occurrence->fresh()->processing_token,
            );
        }
    }

    #[Test]
    public function a_recurrence_after_asset_reassignment_never_reuses_the_previous_clients_work(): void
    {
        Queue::fake();
        [$firstClient, , $asset] = $this->assetContext();
        $this->rule(10, [
            ['type' => 'create_ticket'],
            ['type' => 'create_task'],
        ]);
        $observations = app(RecordRmmAlertObservation::class);
        $observations->handle($asset, $this->observation());
        $observations->handle($asset, $this->observation(['active' => false]));

        $secondClient = Client::factory()->create(['name' => 'Reassigned Client']);
        $secondSite = ClientSite::factory()->create(['client_id' => $secondClient->id, 'name' => 'New Site']);
        $asset->update(['client_id' => $secondClient->id, 'site_id' => $secondSite->id]);
        $observations->handle($asset->fresh(), $this->observation(['title' => 'Backup failed after reassignment']));

        $this->assertDatabaseCount('tickets', 2);
        $this->assertDatabaseCount('tasks', 2);
        $this->assertEqualsCanonicalizing(
            [$firstClient->id, $secondClient->id],
            Ticket::query()->pluck('client_id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$firstClient->id, $secondClient->id],
            Task::query()->pluck('client_id')->all(),
        );
    }

    #[Test]
    public function manually_reassigned_targets_are_never_updated_or_reopened_by_a_recurrence(): void
    {
        Queue::fake();
        [$client, , $asset] = $this->assetContext();
        $rule = $this->rule(10, [
            ['type' => 'create_ticket'],
            ['type' => 'create_task'],
        ]);
        $observations = app(RecordRmmAlertObservation::class);
        $observations->handle($asset, $this->observation());

        $movedTicket = Ticket::query()->firstOrFail();
        $movedTask = Task::query()->firstOrFail();
        $foreignClient = Client::factory()->create(['name' => 'Foreign target owner']);
        $foreignSite = ClientSite::factory()->create([
            'client_id' => $foreignClient->id,
            'name' => 'Foreign target site',
        ]);
        $closed = TicketStatus::query()->where('slug', 'closed')->firstOrFail();
        $reopen = TicketStatus::query()->where('slug', 'in-progress')->firstOrFail();
        $movedTicket->forceFill([
            'client_id' => $foreignClient->id,
            'site_id' => $foreignSite->id,
            'status_id' => $closed->id,
            'workflow_state_key' => 'closed',
            'resolved_at' => now()->subMinute(),
            'closed_at' => now(),
        ])->save();
        $movedTask->forceFill([
            'client_id' => $foreignClient->id,
            'site_id' => $foreignSite->id,
        ])->save();
        $foreignTaskActivityCount = $movedTask->activities()->count();
        $rule->update(['actions' => [
            ['type' => 'reopen_ticket', 'reopen_status_id' => $reopen->id],
            ['type' => 'create_ticket'],
            ['type' => 'create_task'],
        ]]);

        $observations->handle($asset, $this->observation(['active' => false]));
        $observations->handle($asset, $this->observation(['title' => 'Backup failed after target move']));

        $this->assertSame($closed->id, $movedTicket->fresh()->status_id);
        $this->assertNotNull($movedTicket->fresh()->closed_at);
        $this->assertSame($foreignTaskActivityCount, $movedTask->activities()->count());
        $this->assertDatabaseCount('tickets', 2);
        $this->assertDatabaseCount('tasks', 2);
        $this->assertDatabaseHas('tickets', [
            'client_id' => $client->id,
            'asset_id' => $asset->id,
            'closed_at' => null,
        ]);
        $this->assertDatabaseHas('tasks', [
            'client_id' => $client->id,
            'source_type' => 'rmm_alert',
        ]);
        $latestExecution = RmmAlertRuleExecution::query()->latest('id')->firstOrFail();
        $this->assertSame('skipped', data_get($latestExecution->action_results, '0.status'));
        $this->assertSame('no_linked_ticket', data_get($latestExecution->action_results, '0.result'));
    }

    #[Test]
    public function migration_rollback_refuses_to_delete_rule_or_audit_evidence(): void
    {
        $this->rule(10, [['type' => 'ignore']]);
        $migration = require database_path('migrations/2026_08_25_230000_create_rmm_alert_rules_foundation.php');

        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    #[Test]
    public function deleting_an_asset_preserves_occurrence_execution_and_work_item_evidence(): void
    {
        [, , $asset] = $this->assetContext();
        $this->rule(10, [['type' => 'ignore']]);
        app(RecordRmmAlertObservation::class)->handle($asset, $this->observation());

        $asset->delete();

        $this->assertDatabaseCount('asset_alerts', 0);
        $this->assertDatabaseCount('rmm_alert_occurrences', 1);
        $this->assertDatabaseCount('rmm_alert_rule_executions', 1);
        $this->assertDatabaseCount('rmm_alert_work_items', 1);
        $this->assertNull(RmmAlertOccurrence::query()->firstOrFail()->asset_alert_id);
        $this->assertNull(RmmAlertWorkItem::query()->firstOrFail()->asset_alert_id);
    }

    #[Test]
    public function a_closed_linked_ticket_reopens_through_the_configured_workflow_transition(): void
    {
        Queue::fake();
        Notification::fake();
        [, , $asset] = $this->assetContext();
        $rule = $this->rule(10, [['type' => 'create_ticket']]);
        $observations = app(RecordRmmAlertObservation::class);
        $observations->handle($asset, $this->observation());
        $ticket = Ticket::query()->firstOrFail();
        $closed = TicketStatus::query()->where('slug', 'closed')->firstOrFail();
        $inProgress = TicketStatus::query()->where('slug', 'in-progress')->firstOrFail();
        $owner = User::factory()->create();
        $ticket->forceFill([
            'owner_id' => $owner->id,
            'status_id' => $closed->id,
            'workflow_state_key' => 'closed',
            'resolved_at' => now()->subMinute(),
            'closed_at' => now(),
        ])->save();
        $rule->update(['actions' => [[
            'type' => 'reopen_ticket',
            'reopen_status_id' => $inProgress->id,
        ]]]);

        $observations->handle($asset, $this->observation(['active' => false]));
        $observations->handle($asset, $this->observation(['title' => 'Backup failed again']));

        $ticket->refresh();
        $this->assertSame($inProgress->id, $ticket->status_id);
        $this->assertNull($ticket->closed_at);
        $this->assertNull($ticket->resolved_at);
        $this->assertDatabaseHas('ticket_workflow_histories', [
            'ticket_id' => $ticket->id,
            'transition_key' => 'closed-to-in-progress',
        ]);
        $this->assertDatabaseHas('rmm_alert_work_items', [
            'action_type' => 'reopen_ticket',
            'target_id' => $ticket->id,
        ]);
        Notification::assertNothingSent();
    }

    #[Test]
    public function rmm_rule_admin_crud_is_permission_protected_revisioned_and_soft_deleted(): void
    {
        Permission::findOrCreate('integration.view', 'web');
        Permission::findOrCreate('integration.rmm_manage', 'web');
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->givePermissionTo('integration.view');
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->givePermissionTo('integration.rmm_manage');

        $this->actingAs($viewer)
            ->get(route('tech.admin.system.integrations.rmm-alert-rules.index'))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->get(route('tech.admin.system.integrations.nable_rmm.settings'))
            ->assertForbidden();

        $this->actingAs($manager)
            ->get(route('tech.admin.system.integrations.rmm-alert-rules.index'))
            ->assertOk()
            ->assertViewIs('integration::Tech.Admin.System.Integrations.RmmAlertRules.index');

        $this->actingAs($manager)->post(route('tech.admin.system.integrations.rmm-alert-rules.store'), [
            'name' => 'Ignore maintenance alerts',
            'priority' => 10,
            'conditions' => ['subject_contains' => 'maintenance'],
            'actions' => [['type' => 'ignore']],
        ])->assertRedirect(route('tech.admin.system.integrations.rmm-alert-rules.index'));
        $rule = RmmAlertRule::query()->firstOrFail();
        $this->assertFalse($rule->is_active);
        $this->assertSame(1, $rule->revision);

        $this->actingAs($manager)->put(route('tech.admin.system.integrations.rmm-alert-rules.update', $rule), [
            'revision' => 1,
            'name' => $rule->name,
            'priority' => 20,
            'is_active' => 1,
            'conditions' => ['subject_contains' => 'maintenance'],
            'actions' => [['type' => 'ignore']],
        ])->assertRedirect(route('tech.admin.system.integrations.rmm-alert-rules.index'));
        $rule->refresh();
        $this->assertTrue($rule->is_active);
        $this->assertSame(2, $rule->revision);

        $this->actingAs($manager)->put(route('tech.admin.system.integrations.rmm-alert-rules.update', $rule), [
            'revision' => 1,
            'name' => 'Stale overwrite',
            'priority' => 999,
            'is_active' => 0,
            'conditions' => ['subject_contains' => 'stale'],
            'actions' => [['type' => 'ignore']],
        ])->assertSessionHasErrors('revision');
        $rule->refresh();
        $this->assertSame(20, $rule->priority);
        $this->assertTrue($rule->is_active);
        $this->assertSame(2, $rule->revision);

        $this->actingAs($manager)
            ->from(route('tech.admin.system.integrations.rmm-alert-rules.index'))
            ->delete(route('tech.admin.system.integrations.rmm-alert-rules.destroy', $rule))
            ->assertSessionHasErrors('rule');

        $this->actingAs($manager)->put(route('tech.admin.system.integrations.rmm-alert-rules.update', $rule), [
            'revision' => 2,
            'name' => $rule->name,
            'priority' => 20,
            'is_active' => 0,
            'conditions' => ['subject_contains' => 'maintenance'],
            'actions' => [['type' => 'ignore']],
        ])->assertRedirect(route('tech.admin.system.integrations.rmm-alert-rules.index'));
        $rule->refresh();

        $this->actingAs($manager)
            ->delete(route('tech.admin.system.integrations.rmm-alert-rules.destroy', $rule))
            ->assertRedirect(route('tech.admin.system.integrations.rmm-alert-rules.index'));
        $this->assertSoftDeleted('rmm_alert_rules', ['id' => $rule->id]);
    }

    /** @return array{Client, ClientSite, Asset} */
    private function assetContext(): array
    {
        $client = Client::factory()->create(['name' => 'RMM Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Main Site']);
        $asset = Asset::query()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'name' => 'RMM-01',
            'hostname' => 'rmm-01',
            'type' => Asset::TYPE_SERVER,
            'source' => 'rmm',
            'status' => 'active',
        ]);

        return [$client, $site, $asset];
    }

    /** @return array<string, mixed> */
    private function observation(array $overrides = []): array
    {
        return array_replace_recursive([
            'active' => true,
            'integration_type' => 'tactical',
            'external_check_id' => 'check-1',
            'fingerprint' => 'tactical:rmm-01:check-1',
            'title' => 'Backup failed',
            'message' => 'A bounded provider summary.',
            'severity' => 'warning',
            'provider_context' => ['check_type' => 'backup', 'provider_status' => 'failing'],
        ], $overrides);
    }

    /** @param list<array<string, mixed>> $actions */
    private function rule(int $priority, array $actions): RmmAlertRule
    {
        return RmmAlertRule::query()->create([
            'name' => 'RMM Rule '.$priority,
            'is_active' => true,
            'priority' => $priority,
            'stop_processing' => false,
            'conditions' => ['integration_types' => ['tactical']],
            'actions' => $actions,
        ]);
    }
}
