<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Actions\TransitionTicketWorkflow;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Models\TicketWorkflowEvidence;
use App\Modules\Ticket\Models\TicketWorkflowHistory;
use App\Modules\Ticket\Models\TicketWorkflowReview;
use App\Modules\Ticket\Models\TicketWorkflowVersion;
use App\Modules\Ticket\Services\TicketRuleSchema2ActionExecutor;
use App\Modules\Ticket\Services\TicketRuleWorkflowActionExecutor;
use App\Modules\Ticket\Services\TicketWorkflowDefinitionService;
use App\Modules\Ticket\Support\TicketRuleActionFailure;
use App\Modules\Ticket\Support\TicketRuleEventEnvelope;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketRuleWorkflowActionContractTest extends TestCase
{
    use RefreshDatabase;

    private User $automationActor;

    private User $technician;

    private TicketStatus $newStatus;

    private TicketStatus $inProgressStatus;

    private TicketStatus $closedStatus;

    protected function setUp(): void
    {
        parent::setUp();

        app(EnsureTicketDefaults::class)->handle();
        $this->newStatus = TicketStatus::query()->where('slug', 'new')->firstOrFail();
        $this->inProgressStatus = TicketStatus::query()->where('slug', 'in-progress')->firstOrFail();
        $this->closedStatus = TicketStatus::query()->where('is_closed', true)->firstOrFail();

        Permission::findOrCreate('ticket.update', 'web');
        Permission::findOrCreate('ticket.workflow_escalate', 'web');

        $this->technician = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->technician->givePermissionTo(['ticket.update', 'ticket.workflow_escalate']);

        $this->automationActor = User::factory()->create([
            'status' => User::STATUS_DISABLED,
            'is_system_actor' => true,
            'system_actor_key' => 'ticket-rule-workflow-test-'.Str::uuid(),
        ]);
        $this->automationActor->givePermissionTo(['ticket.update', 'ticket.workflow_escalate']);
    }

    #[Test]
    public function schema_two_routes_workflow_actions_with_explicit_creation_and_mutation_phases(): void
    {
        config()->set('ticket_rules.capabilities.actions.select_workflow', true);
        config()->set('ticket_rules.capabilities.actions.transition_workflow', true);

        [$workflow, $version] = $this->publishedWorkflow('Schema 2 Workflow route', [
            $this->state('intake', $this->newStatus->id, 'Intake', true),
            $this->state('work', $this->inProgressStatus->id, 'Work'),
        ], [
            $this->transition('start-work', 'intake', 'work', manualEnabled: false),
        ]);
        $ticket = $this->ticket();
        $executor = app(TicketRuleSchema2ActionExecutor::class);

        $selected = $executor->handle(
            $ticket,
            [
                'type' => TicketRuleWorkflowActionExecutor::SELECT_WORKFLOW,
                'input' => ['workflow_version_id' => $version->id],
            ],
            $this->automationActor,
            $this->event($ticket, 'ticket.created'),
            true,
            $this->key('schema2-select-'.$ticket->id),
        );

        $ticket->refresh();
        $this->assertSame('succeeded', $selected['status']);
        $this->assertSame('creation', data_get($selected, 'authorization.execution_phase'));
        $this->assertTrue(data_get($selected, 'authorization.targets_revalidated'));
        $this->assertSame($workflow->id, $ticket->workflow_id);
        $this->assertCount(1, $selected['derived_events']);

        $transitioned = $executor->handle(
            $ticket,
            [
                'type' => TicketRuleWorkflowActionExecutor::TRANSITION_WORKFLOW,
                'input' => ['transition_key' => 'start-work'],
            ],
            $this->automationActor,
            $this->event($ticket, 'ticket.updated'),
            true,
            $this->key('schema2-transition-'.$ticket->id),
        );

        $ticket->refresh();
        $this->assertSame('succeeded', $transitioned['status']);
        $this->assertSame('mutation', data_get($transitioned, 'authorization.execution_phase'));
        $this->assertSame('work', $ticket->workflow_state_key);
        $this->assertSame($this->inProgressStatus->id, $ticket->status_id);
        $this->assertContains(
            'ticket.workflow_state_changed',
            data_get($transitioned, 'workflow_result.event_keys', []),
        );
    }

    #[Test]
    public function exact_published_workflow_selection_is_creation_only_and_emits_one_composite_event(): void
    {
        [$workflow, $version] = $this->publishedWorkflow(
            'Creation target',
            [$this->state('created-work', $this->inProgressStatus->id, 'Created work', true)],
        );
        $ticket = $this->ticket();
        $this->makeNestedRuleDispatchFailClosed();
        $executor = app(TicketRuleWorkflowActionExecutor::class);

        $result = $executor->handle(
            $ticket,
            TicketRuleWorkflowActionExecutor::SELECT_WORKFLOW,
            ['workflow_version_id' => $version->id],
            $this->automationActor,
            'slice4-select-'.$ticket->id,
            TicketRuleWorkflowActionExecutor::PHASE_CREATION,
        );

        $ticket->refresh();
        $this->assertSame('succeeded', $result['status']);
        $this->assertArrayHasKey('reason_code', $result);
        $this->assertNull($result['reason_code']);
        $this->assertFalse($result['sla_decision']);
        $this->assertCount(1, $result['derived_events']);
        $this->assertSame($workflow->id, $ticket->workflow_id);
        $this->assertSame($version->id, $ticket->workflow_version_id);
        $this->assertSame('created-work', $ticket->workflow_state_key);
        $this->assertSame($this->inProgressStatus->id, $ticket->status_id);
        $this->assertContains('ticket.workflow_changed', $result['workflow_result']['event_keys']);
        $this->assertContains('ticket.status_changed', $result['workflow_result']['event_keys']);
        $this->assertDatabaseHas('ticket_workflow_histories', [
            'ticket_id' => $ticket->id,
            'event_type' => 'rule_workflow_selected',
            'workflow_version_id' => $version->id,
        ]);

        try {
            $executor->handle(
                $ticket,
                TicketRuleWorkflowActionExecutor::SELECT_WORKFLOW,
                ['workflow_version_id' => $version->id],
                $this->automationActor,
                'slice4-select-wrong-phase-'.$ticket->id,
                TicketRuleWorkflowActionExecutor::PHASE_MUTATION,
            );
            $this->fail('Workflow selection outside creation must fail closed.');
        } catch (TicketRuleActionFailure $failure) {
            $this->assertSame('workflow_action_phase_denied', $failure->reasonCode);
        }

        $this->restoreLegacyRuleAuthority();

        $workflow->forceFill(['is_active' => false])->save();
        $inactiveTargetTicket = $this->ticket();
        try {
            $executor->handle(
                $inactiveTargetTicket,
                TicketRuleWorkflowActionExecutor::SELECT_WORKFLOW,
                ['workflow_version_id' => $version->id],
                $this->automationActor,
                'slice4-select-inactive-'.$inactiveTargetTicket->id,
                TicketRuleWorkflowActionExecutor::PHASE_CREATION,
            );
            $this->fail('An inactive Workflow target must fail closed.');
        } catch (TicketRuleActionFailure $failure) {
            $this->assertSame('workflow_action_denied', $failure->reasonCode);
        }

        $workflow->forceFill(['is_active' => true])->save();
        $version->forceFill(['status' => 'draft'])->save();
        $draftTargetTicket = $this->ticket();
        try {
            $executor->handle(
                $draftTargetTicket,
                TicketRuleWorkflowActionExecutor::SELECT_WORKFLOW,
                ['workflow_version_id' => $version->id],
                $this->automationActor,
                'slice4-select-draft-'.$draftTargetTicket->id,
                TicketRuleWorkflowActionExecutor::PHASE_CREATION,
            );
            $this->fail('A draft Workflow version target must fail closed.');
        } catch (TicketRuleActionFailure $failure) {
            $this->assertSame('workflow_action_denied', $failure->reasonCode);
        }
    }

    #[Test]
    public function exact_transition_bypasses_only_manual_visibility_and_rejects_terminal_targets(): void
    {
        $states = [
            $this->state('intake', $this->newStatus->id, 'Intake', true),
            $this->state('review', $this->newStatus->id, 'Review'),
            $this->state('closed', $this->closedStatus->id, 'Closed', false, true),
        ];
        [$workflow] = $this->publishedWorkflow('Exact transition', $states, [
            $this->transition('to-review', 'intake', 'review', manualEnabled: false),
            $this->transition('to-closed', 'review', 'closed', manualEnabled: false),
        ]);
        $ticket = $this->ticket($workflow);
        $executor = app(TicketRuleWorkflowActionExecutor::class);

        $result = $executor->handle(
            $ticket,
            TicketRuleWorkflowActionExecutor::TRANSITION_WORKFLOW,
            ['transition_key' => 'to-review'],
            $this->automationActor,
            'slice4-transition-'.$ticket->id,
            TicketRuleWorkflowActionExecutor::PHASE_MUTATION,
        );

        $ticket->refresh();
        $this->assertSame('review', $ticket->workflow_state_key);
        $this->assertSame($this->newStatus->id, $ticket->status_id);
        $this->assertCount(1, $result['derived_events']);
        $this->assertContains('ticket.workflow_state_changed', $result['workflow_result']['event_keys']);
        $this->assertNotContains('ticket.status_changed', $result['workflow_result']['event_keys']);

        $retry = $executor->handle(
            $ticket,
            TicketRuleWorkflowActionExecutor::TRANSITION_WORKFLOW,
            ['transition_key' => 'to-review'],
            $this->automationActor,
            'slice4-transition-'.$ticket->id,
            TicketRuleWorkflowActionExecutor::PHASE_MUTATION,
        );
        $this->assertSame('no_change', $retry['status']);
        $this->assertSame(1, TicketWorkflowHistory::query()
            ->where('idempotency_key', 'slice4-transition-'.$ticket->id)
            ->count());

        try {
            $executor->handle(
                $ticket,
                TicketRuleWorkflowActionExecutor::TRANSITION_WORKFLOW,
                ['transition_key' => 'to-closed'],
                $this->automationActor,
                'slice4-terminal-'.$ticket->id,
                TicketRuleWorkflowActionExecutor::PHASE_MUTATION,
            );
            $this->fail('A rule-driven transition must not close the Ticket.');
        } catch (TicketRuleActionFailure $failure) {
            $this->assertSame('workflow_action_denied', $failure->reasonCode);
        }

        $this->assertSame('review', $ticket->refresh()->workflow_state_key);
        $this->assertNull($ticket->closed_at);
    }

    #[Test]
    public function exact_transition_rechecks_workflow_action_policy_and_transition_requirements(): void
    {
        $blockedState = $this->state('blocked-intake', $this->newStatus->id, 'Blocked intake', true);
        $blockedState['action_policy'] = [
            'change_status' => [
                'mode' => 'blocked',
                'reason' => 'Status changes are blocked in this Workflow state.',
                'requirements' => ['match' => 'all', 'groups' => []],
            ],
        ];
        [$workflow] = $this->publishedWorkflow('Policy transition', [
            $blockedState,
            $this->state('work', $this->inProgressStatus->id, 'Work'),
        ], [
            array_replace($this->transition('start-work', 'blocked-intake', 'work'), [
                'requirements' => $this->tree('ticket.internal_note'),
            ]),
        ]);
        $ticket = $this->ticket($workflow);

        try {
            app(TicketRuleWorkflowActionExecutor::class)->handle(
                $ticket,
                TicketRuleWorkflowActionExecutor::TRANSITION_WORKFLOW,
                ['transition_key' => 'start-work'],
                $this->automationActor,
                'slice4-policy-block-'.$ticket->id,
                TicketRuleWorkflowActionExecutor::PHASE_MUTATION,
            );
            $this->fail('Workflow action policy must deny the exact transition.');
        } catch (TicketRuleActionFailure $failure) {
            $this->assertSame('workflow_action_denied', $failure->reasonCode);
        }

        $this->assertSame('blocked-intake', $ticket->refresh()->workflow_state_key);
        $this->assertDatabaseMissing('ticket_workflow_histories', [
            'idempotency_key' => 'slice4-policy-block-'.$ticket->id,
        ]);
    }

    #[Test]
    public function workflow_switch_rechecks_exact_source_target_requirements_assignment_and_evidence(): void
    {
        [$source, $sourceVersion] = $this->publishedWorkflow(
            'Switch source',
            [$this->state('source-intake', $this->newStatus->id, 'Source intake', true)],
        );
        $targetState = $this->state('target-work', $this->inProgressStatus->id, 'Target work', true);
        $targetState['assignment_policy'] = [
            'strategy' => 'unassigned',
            'eligible_user_ids' => [],
            'required_permissions' => [],
        ];
        [$target, $targetVersion] = $this->publishedWorkflow('Switch target', [$targetState]);

        $ticket = $this->ticket($source);
        $this->makeNestedRuleDispatchFailClosed();
        TicketWorkflowReview::query()->create([
            'ticket_id' => $ticket->id,
            'workflow_version_id' => $sourceVersion->id,
            'state_key' => 'source-intake',
            'gate_key' => 'quality',
            'status' => 'approved',

            'evidence_fingerprint' => hash('sha256', 'review-'.$ticket->id),
            'requested_by' => $this->technician->id,
            'reviewed_by' => $this->technician->id,
            'decided_at' => now(),
        ]);
        $evidence = TicketWorkflowEvidence::query()->create([
            'ticket_id' => $ticket->id,
            'evidence_type' => 'manual',
            'scope_key' => 'switch-test',
            'fingerprint' => hash('sha256', 'evidence-'.$ticket->id),
            'created_by' => $this->technician->id,
            'evidenced_at' => now(),
        ]);

        $result = app(TicketRuleWorkflowActionExecutor::class)->handle(
            $ticket,
            TicketRuleWorkflowActionExecutor::SWITCH_WORKFLOW,
            [
                'source_workflow_version_id' => $sourceVersion->id,
                'target_workflow_version_id' => $targetVersion->id,
                'mapping_strategy' => 'state_key',
                'target_state_key' => 'target-work',
            ],
            $this->automationActor,
            'slice4-switch-'.$ticket->id,
            TicketRuleWorkflowActionExecutor::PHASE_MUTATION,
        );

        $ticket->refresh();
        $this->assertSame($target->id, $ticket->workflow_id);
        $this->assertSame($targetVersion->id, $ticket->workflow_version_id);
        $this->assertSame('target-work', $ticket->workflow_state_key);
        $this->assertSame($this->inProgressStatus->id, $ticket->status_id);
        $this->assertNull($ticket->owner_id);
        $this->assertSame('invalidated', $ticket->workflowReviews()->firstOrFail()->status);
        $this->assertNull($evidence->refresh()->invalidated_at);
        $this->assertTrue($result['assignment_decision']);
        $this->assertContains('ticket.workflow_changed', $result['workflow_result']['event_keys']);
        $this->assertContains('ticket.status_changed', $result['workflow_result']['event_keys']);
        $this->assertContains('ticket.assignment_changed', $result['workflow_result']['event_keys']);
        $this->assertSame(1, $result['workflow_result']['evidence_invalidation']['reviews_invalidated']);
        $this->assertSame(0, $result['workflow_result']['evidence_invalidation']['evidence_invalidated']);

        $history = TicketWorkflowHistory::query()
            ->where('idempotency_key', 'slice4-switch-'.$ticket->id)
            ->firstOrFail();
        $this->assertSame($sourceVersion->id, data_get($history->metadata, 'source_workflow_version_id'));
        $this->assertSame($targetVersion->id, data_get($history->metadata, 'target_workflow_version_id'));
        $this->assertTrue((bool) data_get($history->metadata, 'evidence_preserved'));

        try {
            app(TicketRuleWorkflowActionExecutor::class)->handle(
                $ticket,
                TicketRuleWorkflowActionExecutor::SWITCH_WORKFLOW,
                [
                    'source_workflow_version_id' => $sourceVersion->id,
                    'target_workflow_version_id' => $targetVersion->id,
                    'mapping_strategy' => 'state_key',
                    'target_state_key' => 'target-work',
                ],
                $this->automationActor,
                'slice4-switch-stale-source-'.$ticket->id,
                TicketRuleWorkflowActionExecutor::PHASE_MUTATION,
            );
            $this->fail('A stale exact source Workflow version must fail closed.');
        } catch (TicketRuleActionFailure $failure) {
            $this->assertSame('workflow_action_denied', $failure->reasonCode);
        }
    }

    #[Test]
    public function pause_blocks_only_rule_driven_movement_and_audits_reason_without_raw_text(): void
    {
        [$workflow] = $this->publishedWorkflow('Pause behavior', [
            $this->state('intake', $this->newStatus->id, 'Intake', true),
            $this->state('work', $this->inProgressStatus->id, 'Work'),
        ], [
            $this->transition('start-work', 'intake', 'work'),
        ]);
        $ticket = $this->ticket($workflow);
        $executor = app(TicketRuleWorkflowActionExecutor::class);
        $secretReason = 'Internal pause reason that must not enter rule audit evidence.';

        $paused = $executor->handle(
            $ticket,
            TicketRuleWorkflowActionExecutor::PAUSE_WORKFLOW_AUTOMATION,
            ['reason' => $secretReason],
            $this->automationActor,
            'slice4-pause-'.$ticket->id,
            TicketRuleWorkflowActionExecutor::PHASE_MUTATION,
        );

        $ticket->refresh();
        $this->assertNotNull($ticket->getAttribute('rule_workflow_paused_at'));
        $this->assertSame($secretReason, $ticket->getAttribute('rule_workflow_pause_reason'));
        $this->assertSame(hash('sha256', $secretReason), data_get(
            $paused,
            'changes.rule_workflow_pause_reason.after.sha256',
        ));
        $this->assertStringNotContainsString(
            $secretReason,
            json_encode([
                $paused,
                TicketWorkflowHistory::query()->where('idempotency_key', 'slice4-pause-'.$ticket->id)->firstOrFail()->toArray(),
                TicketEvent::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail()->toArray(),
            ], JSON_THROW_ON_ERROR),
        );

        try {
            $executor->handle(
                $ticket,
                TicketRuleWorkflowActionExecutor::TRANSITION_WORKFLOW,
                ['transition_key' => 'start-work'],
                $this->automationActor,
                'slice4-paused-transition-'.$ticket->id,
                TicketRuleWorkflowActionExecutor::PHASE_MUTATION,
            );
            $this->fail('Paused rule-driven Workflow movement must fail closed.');
        } catch (TicketRuleActionFailure $failure) {
            $this->assertSame('workflow_action_denied', $failure->reasonCode);
        }

        app(TransitionTicketWorkflow::class)->handle(
            $ticket,
            'start-work',
            $this->technician,
            'slice4-manual-transition-'.$ticket->id,
            notificationsEnabled: false,
        );
        $this->assertSame('work', $ticket->refresh()->workflow_state_key);
        $this->assertNotNull($ticket->getAttribute('rule_workflow_paused_at'));

        $resumed = $executor->handle(
            $ticket,
            TicketRuleWorkflowActionExecutor::RESUME_WORKFLOW_AUTOMATION,
            [],
            $this->automationActor,
            'slice4-resume-'.$ticket->id,
            TicketRuleWorkflowActionExecutor::PHASE_MUTATION,
        );

        $ticket->refresh();
        $this->assertSame('succeeded', $resumed['status']);
        $this->assertNull($ticket->getAttribute('rule_workflow_paused_at'));
        $this->assertNull($ticket->getAttribute('rule_workflow_pause_reason'));
        $this->assertSame('work', $ticket->workflow_state_key);
    }

    #[Test]
    public function workflow_preview_returns_assignment_consequences_without_writes(): void
    {
        $targetState = $this->state('preview-target', $this->inProgressStatus->id, 'Preview target', true);
        $targetState['assignment_policy'] = [
            'strategy' => 'unassigned',
            'eligible_user_ids' => [],
            'required_permissions' => [],
        ];
        [, $targetVersion] = $this->publishedWorkflow('Preview workflow', [$targetState]);
        $ticket = $this->ticket();
        $before = $ticket->only([
            'workflow_id', 'workflow_version_id', 'workflow_state_key', 'status_id', 'owner_id',
        ]);
        $historyCount = TicketWorkflowHistory::query()->count();
        $eventCount = TicketEvent::query()->count();

        $result = app(TicketRuleWorkflowActionExecutor::class)->handle(
            $ticket,
            TicketRuleWorkflowActionExecutor::SELECT_WORKFLOW,
            ['workflow_version_id' => $targetVersion->id],
            $this->automationActor,
            'slice4-preview-'.$ticket->id,
            TicketRuleWorkflowActionExecutor::PHASE_CREATION,
            apply: false,
        );

        $this->assertSame('planned', $result['status']);
        $this->assertTrue($result['assignment_decision']);
        $this->assertSame('unassigned', data_get($result, 'workflow_result.assignment_result.strategy'));
        $this->assertSame($before, $ticket->refresh()->only(array_keys($before)));
        $this->assertSame($historyCount, TicketWorkflowHistory::query()->count());
        $this->assertSame($eventCount, TicketEvent::query()->count());
    }

    #[Test]
    public function ordered_workflow_preview_uses_the_private_projected_ticket_between_actions(): void
    {
        [, $version] = $this->publishedWorkflow('Ordered preview workflow', [
            $this->state('preview-intake', $this->newStatus->id, 'Preview intake', true),
            $this->state('preview-work', $this->inProgressStatus->id, 'Preview work'),
        ], [
            $this->transition('preview-start-work', 'preview-intake', 'preview-work', manualEnabled: false),
        ]);
        $ticket = $this->ticket();
        $before = $ticket->only([
            'workflow_id',
            'workflow_version_id',
            'workflow_state_key',
            'status_id',
            'owner_id',
        ]);
        $historyCount = TicketWorkflowHistory::query()->count();
        $eventCount = TicketEvent::query()->count();
        $executor = app(TicketRuleWorkflowActionExecutor::class);

        $selected = $executor->handle(
            $ticket,
            TicketRuleWorkflowActionExecutor::SELECT_WORKFLOW,
            ['workflow_version_id' => $version->id],
            $this->automationActor,
            'slice6-preview-select-'.$ticket->id,
            TicketRuleWorkflowActionExecutor::PHASE_CREATION,
            apply: false,
        );
        $shadow = clone $ticket;
        foreach ($selected['changes'] as $field => $change) {
            $shadow->setAttribute($field, $change['after']);
        }

        $transitioned = $executor->handle(
            $shadow,
            TicketRuleWorkflowActionExecutor::TRANSITION_WORKFLOW,
            ['transition_key' => 'preview-start-work'],
            $this->automationActor,
            'slice6-preview-transition-'.$ticket->id,
            TicketRuleWorkflowActionExecutor::PHASE_MUTATION,
            apply: false,
        );

        $this->assertSame('planned', $selected['status']);
        $this->assertSame('planned', $transitioned['status']);
        $this->assertSame(
            'preview-intake',
            data_get($transitioned, 'changes.workflow_state_key.before'),
        );
        $this->assertSame(
            'preview-work',
            data_get($transitioned, 'changes.workflow_state_key.after'),
        );
        $this->assertSame(
            $this->inProgressStatus->id,
            data_get($transitioned, 'changes.status_id.after'),
        );
        $this->assertSame($before, $ticket->refresh()->only(array_keys($before)));
        $this->assertSame($historyCount, TicketWorkflowHistory::query()->count());
        $this->assertSame($eventCount, TicketEvent::query()->count());
    }

    #[Test]
    public function a_failed_branch_savepoint_rolls_back_workflow_writes_and_leaks_no_callbacks(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The deterministic trigger failure contract is SQLite-specific.');
        }

        Queue::fake();
        [$workflow] = $this->publishedWorkflow('Savepoint workflow', [
            $this->state('intake', $this->newStatus->id, 'Intake', true),
            $this->state('work', $this->inProgressStatus->id, 'Work'),
        ], [
            $this->transition('start-work', 'intake', 'work'),
        ]);
        $ticket = $this->ticket($workflow);
        $before = $ticket->only(['workflow_state_key', 'status_id', 'owner_id']);
        $eventCount = TicketEvent::query()->where('ticket_id', $ticket->id)->count();

        DB::transaction(function () use ($ticket): void {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER fail_slice4_workflow_history
BEFORE INSERT ON ticket_workflow_histories
WHEN NEW.idempotency_key = 'slice4-forced-history-failure'
BEGIN
    SELECT RAISE(ABORT, 'forced Slice 4 history failure');
END
SQL);

            try {
                app(TicketRuleWorkflowActionExecutor::class)->handle(
                    $ticket,
                    TicketRuleWorkflowActionExecutor::TRANSITION_WORKFLOW,
                    ['transition_key' => 'start-work'],
                    $this->automationActor,
                    'slice4-forced-history-failure',
                    TicketRuleWorkflowActionExecutor::PHASE_MUTATION,
                );
                $this->fail('The forced history failure must abort the Workflow action savepoint.');
            } catch (QueryException) {
                $this->assertTrue(true);
            } finally {
                DB::unprepared('DROP TRIGGER IF EXISTS fail_slice4_workflow_history');
            }

            $this->assertSame('intake', $ticket->refresh()->workflow_state_key);
            $this->assertSame($this->newStatus->id, $ticket->status_id);
        });

        $this->assertSame($before, $ticket->refresh()->only(array_keys($before)));
        $this->assertSame($eventCount, TicketEvent::query()->where('ticket_id', $ticket->id)->count());
        $this->assertDatabaseMissing('ticket_workflow_histories', [
            'idempotency_key' => 'slice4-forced-history-failure',
        ]);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function protected_actor_permission_and_target_requirement_failures_are_closed_without_mutation(): void
    {
        [$source, $sourceVersion] = $this->publishedWorkflow(
            'Guard source',
            [$this->state('source', $this->newStatus->id, 'Source', true)],
        );
        $requiredState = $this->state('qualified', $this->inProgressStatus->id, 'Qualified', true);
        $requiredState['requirements'] = $this->tree('ticket.internal_note');
        [, $targetVersion] = $this->publishedWorkflow('Guard target', [$requiredState]);
        $ticket = $this->ticket($source);
        $before = $ticket->only(['workflow_id', 'workflow_version_id', 'workflow_state_key', 'status_id']);

        try {
            app(TicketRuleWorkflowActionExecutor::class)->handle(
                $ticket,
                TicketRuleWorkflowActionExecutor::SWITCH_WORKFLOW,
                [
                    'source_workflow_version_id' => $sourceVersion->id,
                    'target_workflow_version_id' => $targetVersion->id,
                    'mapping_strategy' => 'state_key',
                    'target_state_key' => 'qualified',
                ],
                $this->technician,
                'slice4-human-actor-'.$ticket->id,
                TicketRuleWorkflowActionExecutor::PHASE_MUTATION,
            );
            $this->fail('A human initiator must not replace the protected automation actor.');
        } catch (TicketRuleActionFailure $failure) {
            $this->assertSame('workflow_automation_actor_required', $failure->reasonCode);
        }

        try {
            app(TicketRuleWorkflowActionExecutor::class)->handle(
                $ticket,
                TicketRuleWorkflowActionExecutor::SWITCH_WORKFLOW,
                [
                    'source_workflow_version_id' => $sourceVersion->id,
                    'target_workflow_version_id' => $targetVersion->id,
                    'mapping_strategy' => 'state_key',
                    'target_state_key' => 'qualified',
                ],
                $this->automationActor,
                'slice4-requirement-block-'.$ticket->id,
                TicketRuleWorkflowActionExecutor::PHASE_MUTATION,
            );
            $this->fail('Unsatisfied target Workflow requirements must fail closed.');
        } catch (TicketRuleActionFailure $failure) {
            $this->assertSame('workflow_action_denied', $failure->reasonCode);
        }

        $this->assertSame($before, $ticket->refresh()->only(array_keys($before)));
        $this->assertDatabaseMissing('ticket_workflow_histories', [
            'idempotency_key' => 'slice4-requirement-block-'.$ticket->id,
        ]);
    }

    private function makeNestedRuleDispatchFailClosed(): void
    {
        config()->set('ticket_rules.v2_enabled', false);

        $this->assertSame(1, TicketRuleAuthorityFence::query()
            ->whereKey(TicketRuleAuthorityFence::SCOPE)
            ->update([
                'runtime_authority' => TicketRuleAuthorityFence::AUTHORITY_V2,
            ]));
    }

    private function restoreLegacyRuleAuthority(): void
    {
        $this->assertSame(1, TicketRuleAuthorityFence::query()
            ->whereKey(TicketRuleAuthorityFence::SCOPE)
            ->update([
                'runtime_authority' => TicketRuleAuthorityFence::AUTHORITY_LEGACY,
            ]));
    }

    private function event(Ticket $ticket, string $eventKey): TicketRuleEventEnvelope
    {
        return new TicketRuleEventEnvelope(
            ticketId: (int) $ticket->id,
            eventKey: $eventKey,
            sourceChannel: 'manual',
            sourceAction: 'TicketRuleWorkflowActionContractTest',
            changedFields: $eventKey === 'ticket.created' ? ['created'] : ['workflow_state_key'],
            before: [],
            after: [],
            facts: [],
            initiatorType: 'user',
            initiatorId: (int) $this->technician->id,
            automationActorId: (int) $this->automationActor->id,
            correlationUuid: (string) Str::uuid(),
            causationUuid: null,
            parentEventId: null,
            parentActionResultId: null,
            chainDepth: 0,
            occurredAt: CarbonImmutable::now(),
            fingerprint: $this->key('event-fingerprint-'.$eventKey.'-'.$ticket->id),
            idempotencyKey: $this->key('event-delivery-'.$eventKey.'-'.$ticket->id),
        );
    }

    private function key(string $scope): string
    {
        return hash('sha256', 'slice4-workflow-action-contract:'.$scope);
    }

    /**
     * @param  list<array<string, mixed>>  $states
     * @param  list<array<string, mixed>>  $transitions
     * @return array{TicketWorkflow, TicketWorkflowVersion}
     */
    private function publishedWorkflow(string $name, array $states, array $transitions = []): array
    {
        $workflow = TicketWorkflow::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 100,
        ]);
        $definitions = app(TicketWorkflowDefinitionService::class);
        $definitions->saveDraft($workflow, [
            'states' => $states,
            'transitions' => $transitions,
            'escalation_paths' => [],
        ]);
        $version = $definitions->publish($workflow, $this->technician);

        return [$workflow->refresh(), $version];
    }

    /** @return array<string, mixed> */
    private function state(
        string $key,
        int $statusId,
        string $name,
        bool $initial = false,
        bool $terminal = false,
    ): array {
        return [
            'state_key' => $key,
            'ticket_status_id' => $statusId,
            'name' => $name,
            'is_initial' => $initial,
            'is_terminal' => $terminal,
            'requirements' => ['match' => 'all', 'groups' => []],
            'action_policy' => [],
            'assignment_policy' => [
                'strategy' => 'keep_if_eligible',
                'eligible_user_ids' => [],
                'required_permissions' => [],
            ],
            'commercial_policy' => [],
            'sort_order' => $initial ? 10 : 20,
        ];
    }

    /** @return array<string, mixed> */
    private function transition(
        string $key,
        string $from,
        string $to,
        bool $manualEnabled = true,
    ): array {
        return [
            'transition_key' => $key,
            'from_state_key' => $from,
            'to_state_key' => $to,
            'label' => Str::headline($key),
            'manual_enabled' => $manualEnabled,
            'trigger_actions' => [],
            'requirements' => ['match' => 'all', 'groups' => []],
            'sort_order' => 10,
        ];
    }

    /** @return array<string, mixed> */
    private function tree(string $fact): array
    {
        return [
            'match' => 'all',
            'groups' => [[
                'match' => 'all',
                'conditions' => [[
                    'fact' => $fact,
                    'operator' => 'is_true',
                    'value' => null,
                ]],
            ]],
        ];
    }

    private function ticket(?TicketWorkflow $workflow = null): Ticket
    {
        return app(StoreTicket::class)->handle(array_filter([
            'subject' => 'Slice 4 Workflow contract '.Str::random(8),
            'workflow_id' => $workflow?->id,
            '_skip_initial_description_note' => true,
            'suppress_notifications' => true,
        ], fn (mixed $value): bool => $value !== null), $this->technician);
    }
}
