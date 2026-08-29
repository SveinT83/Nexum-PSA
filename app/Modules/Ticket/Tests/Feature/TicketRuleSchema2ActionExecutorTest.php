<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Services\TicketAssignmentEngine;
use App\Modules\Ticket\Services\TicketRuleSchema2ActionExecutor;
use App\Modules\Ticket\Support\TicketRuleActionFailure;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleEventEnvelope;
use App\Modules\Ticket\Support\TicketRuleMutationEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketRuleSchema2ActionExecutorTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ticket_rules.v2_enabled', false);
        $permissions = [
            'ticket.update',
            'ticket.assign',
            'ticket.note_internal',
            'signal.action.execute',
        ];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->technician = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor = User::factory()->create([
            'status' => User::STATUS_DISABLED,
            'is_system_actor' => true,
            'system_actor_key' => 'ticket-rule-schema2-test-'.Str::uuid(),
        ]);
        $this->actor->givePermissionTo($permissions);

        $providers = app(TicketRuleActionProviderRegistry::class);
        config()->set(
            'ticket_rules.capabilities.actions',
            array_fill_keys(array_keys($providers->definitions()), true),
        );
    }

    #[Test]
    public function snapshots_and_preview_never_retain_private_action_text(): void
    {
        $ticket = $this->ticket();
        $executor = app(TicketRuleSchema2ActionExecutor::class);
        $privateNote = 'Private note with customer credentials.';
        $privateSubject = 'Private customer incident summary.';
        $privateSignalSummary = 'Private signal context.';
        $note = ['type' => 'add_internal_note', 'input' => ['body' => $privateNote]];
        $fields = [
            'type' => 'set_ticket_fields',
            'input' => ['fields' => ['subject' => $privateSubject]],
        ];
        $signal = [
            'type' => 'emit_signal',
            'input' => [
                'signal_type' => 'security_alert',
                'summary' => $privateSignalSummary,
            ],
        ];

        $noteSnapshot = json_encode($executor->snapshot($note), JSON_THROW_ON_ERROR);
        $fieldSnapshot = json_encode($executor->snapshot($fields), JSON_THROW_ON_ERROR);
        $signalSnapshot = json_encode($executor->snapshot($signal), JSON_THROW_ON_ERROR);
        $preview = $executor->handle(
            $ticket,
            $note,
            $this->actor,
            $this->event($ticket),
            false,
            $this->key('preview-note'),
        );

        $this->assertStringNotContainsString($privateNote, $noteSnapshot);
        $this->assertStringNotContainsString($privateSubject, $fieldSnapshot);
        $this->assertStringNotContainsString($privateSignalSummary, $signalSnapshot);
        $this->assertSame(hash('sha256', $privateNote), data_get(
            $executor->snapshot($note),
            'input.body.sha256',
        ));
        $this->assertSame('planned', $preview['status']);
        $this->assertSame([], $preview['derived_events']);
        $this->assertTrue($preview['authorization']['targets_revalidated']);
        $this->assertDatabaseCount('ticket_messages', 0);
    }

    #[Test]
    public function tags_and_internal_notes_delegate_to_canonical_actions_and_return_safe_events(): void
    {
        $ticket = $this->ticket();
        $tag = Tag::query()->create([
            'name' => 'Automation Tag',
            'slug' => 'automation-tag',
            'active' => true,
        ]);
        $executor = app(TicketRuleSchema2ActionExecutor::class);
        $event = $this->event($ticket);

        $tagResult = $executor->handle(
            $ticket,
            ['type' => 'add_tags', 'input' => ['tag_ids' => [$tag->id]]],
            $this->actor,
            $event,
            true,
            $this->key('add-tag'),
        );

        $this->assertSame('succeeded', $tagResult['status']);
        $this->assertFalse($tagResult['assignment_decision']);
        $this->assertCount(1, $tagResult['derived_events']);
        $this->assertSame('ticket.tags_changed', $tagResult['derived_events'][0]->eventKey);
        $this->assertTrue($ticket->tags()->whereKey($tag->id)->exists());

        $rawBody = 'Rule-created private system note.';
        $note = ['type' => 'add_internal_note', 'input' => ['body' => $rawBody]];
        $noteResult = $executor->handle(
            $ticket,
            $note,
            $this->actor,
            $event,
            true,
            $this->key('add-note'),
        );
        $message = TicketMessage::query()->latest('id')->firstOrFail();

        $this->assertSame('succeeded', $noteResult['status']);
        $this->assertSame('system', $message->author_type);
        $this->assertSame('internal_note', $message->type);
        $this->assertSame('internal', $message->visibility);
        $this->assertSame($this->actor->id, (int) $message->author_id);
        $this->assertSame('ticket.message_added', $noteResult['derived_events'][0]->eventKey);
        $this->assertStringNotContainsString(
            $rawBody,
            json_encode($noteResult, JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString(
            $rawBody,
            json_encode($executor->snapshot($note), JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function field_queue_owner_and_sla_actions_return_coordinator_compatible_results(): void
    {
        $ticket = $this->ticket();
        $queue = TicketQueue::query()->create([
            'name' => 'Rule Queue',
            'slug' => 'rule-queue',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 90,
        ]);
        $owner = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $sla = $this->sla('Rule SLA');
        $executor = app(TicketRuleSchema2ActionExecutor::class);
        $event = $this->event($ticket);

        $queueResult = $executor->handle(
            $ticket,
            ['type' => 'set_queue', 'input' => ['queue_id' => $queue->id]],
            $this->actor,
            $event,
            true,
            $this->key('queue'),
        );
        $this->assertSame('succeeded', $queueResult['status']);
        $this->assertTrue($queueResult['assignment_decision']);
        $this->assertFalse($queueResult['sla_decision']);
        $this->assertSame($queue->id, (int) $ticket->queue_id);
        $this->assertInstanceOf(TicketRuleMutationEvent::class, $queueResult['derived_events'][0]);

        $ownerResult = $executor->handle(
            $ticket,
            ['type' => 'assign_owner', 'input' => ['owner_id' => $owner->id]],
            $this->actor,
            $event,
            true,
            $this->key('owner'),
        );
        $this->assertSame('succeeded', $ownerResult['status']);
        $this->assertTrue($ownerResult['assignment_decision']);
        $this->assertSame($owner->id, (int) $ticket->owner_id);

        $privateSubject = 'Executor-updated private subject.';
        $fieldResult = $executor->handle(
            $ticket,
            [
                'type' => 'set_ticket_fields',
                'input' => [
                    'fields' => [
                        'subject' => $privateSubject,
                        'impact' => 4,
                        'sla_id' => $sla->id,
                    ],
                ],
            ],
            $this->actor,
            $event,
            true,
            $this->key('fields'),
        );

        $this->assertSame('succeeded', $fieldResult['status']);
        $this->assertTrue($fieldResult['sla_decision']);
        $this->assertSame($privateSubject, $ticket->subject);
        $this->assertSame($sla->id, (int) $ticket->sla_id);
        $this->assertSame('ticket_rule', $ticket->sla_source);
        $this->assertStringNotContainsString(
            $privateSubject,
            json_encode($fieldResult['changes'], JSON_THROW_ON_ERROR),
        );
        $this->assertSame(
            ['derived_events', 'assignment_decision', 'sla_decision'],
            array_values(array_intersect(
                ['derived_events', 'assignment_decision', 'sla_decision'],
                array_keys($fieldResult),
            )),
        );
    }

    #[Test]
    public function rerun_assignment_delegates_to_the_engine_and_normalizes_its_actual_delta(): void
    {
        $ticket = $this->ticket();
        $newOwner = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->mock(TicketAssignmentEngine::class, function (MockInterface $mock) use ($newOwner): void {
            $mock->shouldReceive('assign')
                ->once()
                ->withArgs(fn (Ticket $candidate, bool $force): bool => $candidate->exists && $force)
                ->andReturnUsing(function (Ticket $candidate) use ($newOwner): int {
                    $candidate->forceFill(['owner_id' => $newOwner->id])->save();

                    return (int) $newOwner->id;
                });
        });

        $result = app(TicketRuleSchema2ActionExecutor::class)->handle(
            $ticket,
            ['type' => 'rerun_assignment', 'input' => []],
            $this->actor,
            $this->event($ticket),
            true,
            $this->key('rerun'),
        );

        $this->assertSame('succeeded', $result['status']);
        $this->assertTrue($result['assignment_decision']);
        $this->assertSame($newOwner->id, (int) $ticket->owner_id);
        $this->assertSame(['owner_id'], $result['derived_events'][0]->changedFields);
        $this->assertSame(['owner_changed'], $result['derived_events'][0]->classification['assignment_changes']);
    }

    #[Test]
    public function capability_actor_permission_target_and_guard_drift_fail_closed(): void
    {
        $ticket = $this->ticket();
        $event = $this->event($ticket);
        $executor = app(TicketRuleSchema2ActionExecutor::class);
        $inactive = Tag::query()->create([
            'name' => 'Inactive Tag',
            'slug' => 'inactive-tag',
            'active' => false,
        ]);

        $this->assertSame('target_missing', $this->failureCode(fn () => $executor->handle(
            $ticket,
            ['type' => 'add_tags', 'input' => ['tag_ids' => [$inactive->id]]],
            $this->actor,
            $event,
            true,
            $this->key('inactive'),
        )));

        config()->set('ticket_rules.capabilities.actions.add_tags', false);
        $this->assertSame('action_capability_disabled', $this->failureCode(fn () => $executor->handle(
            $ticket,
            ['type' => 'add_tags', 'input' => ['tag_ids' => [$inactive->id]]],
            $this->actor,
            $event,
            true,
            $this->key('disabled'),
        )));
        config()->set('ticket_rules.capabilities.actions.add_tags', true);

        $human = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->assertSame('automation_actor_required', $this->failureCode(fn () => $executor->handle(
            $ticket,
            ['type' => 'unassign_owner', 'input' => []],
            $human,
            $this->event($ticket, $human),
            true,
            $this->key('human'),
        )));

        $unprivilegedActor = User::factory()->create([
            'status' => User::STATUS_DISABLED,
            'is_system_actor' => true,
            'system_actor_key' => 'ticket-rule-unprivileged-'.Str::uuid(),
        ]);
        $this->assertSame('automation_permission_denied', $this->failureCode(fn () => $executor->handle(
            $ticket,
            ['type' => 'unassign_owner', 'input' => []],
            $unprivilegedActor,
            $this->event($ticket, $unprivilegedActor),
            true,
            $this->key('permission'),
        )));

        $ticket->forceFill(['closed_at' => now()])->save();
        $this->assertSame('action_guard_denied', $this->failureCode(fn () => $executor->handle(
            $ticket,
            ['type' => 'set_ticket_fields', 'input' => ['fields' => ['impact' => 5]]],
            $this->actor,
            $event,
            true,
            $this->key('closed'),
        )));
    }

    #[Test]
    public function signal_handoff_is_queued_after_commit_and_signal_sources_are_loop_suppressed(): void
    {
        $ticket = $this->ticket();
        $executor = app(TicketRuleSchema2ActionExecutor::class);
        $summary = 'Sensitive but required runtime signal summary.';
        $action = [
            'type' => 'emit_signal',
            'input' => [
                'signal_type' => 'Security Alert',
                'severity' => 'warning',
                'confidence' => 82,
                'summary' => $summary,
            ],
        ];

        $result = $executor->handle(
            $ticket,
            $action,
            $this->actor,
            $this->event($ticket),
            true,
            $this->key('signal'),
        );

        $this->assertSame('queued', $result['status']);
        $this->assertSame('emit_signal', $result['after_commit']['type']);
        $this->assertSame('security_alert', $result['after_commit']['signal_type']);
        $this->assertSame($summary, $result['after_commit']['summary']);
        $this->assertSame([], $result['derived_events']);
        $this->assertStringNotContainsString(
            $summary,
            json_encode($executor->snapshot($action), JSON_THROW_ON_ERROR),
        );

        $loopEvent = $this->event($ticket);
        $loopEvent = new TicketRuleEventEnvelope(
            ticketId: $loopEvent->ticketId,
            eventKey: $loopEvent->eventKey,
            sourceChannel: 'signal',
            sourceAction: $loopEvent->sourceAction,
            changedFields: $loopEvent->changedFields,
            before: $loopEvent->before,
            after: $loopEvent->after,
            facts: $loopEvent->facts,
            initiatorType: $loopEvent->initiatorType,
            initiatorId: $loopEvent->initiatorId,
            automationActorId: $loopEvent->automationActorId,
            correlationUuid: $loopEvent->correlationUuid,
            causationUuid: $loopEvent->causationUuid,
            parentEventId: $loopEvent->parentEventId,
            parentActionResultId: $loopEvent->parentActionResultId,
            chainDepth: $loopEvent->chainDepth,
            occurredAt: $loopEvent->occurredAt,
            fingerprint: $loopEvent->fingerprint,
            idempotencyKey: $loopEvent->idempotencyKey,
        );
        $loop = $executor->handle(
            $ticket,
            $action,
            $this->actor,
            $loopEvent,
            true,
            $this->key('signal-loop'),
        );

        $this->assertSame('no_change', $loop['status']);
        $this->assertSame('signal_source_loop_suppressed', $loop['reason_code']);
        $this->assertNull($loop['after_commit']);
    }

    private function ticket(): Ticket
    {
        return Ticket::factory()->create(['owner_id' => $this->technician->id]);
    }

    private function key(string $scope): string
    {
        return hash('sha256', 'schema2-action-test:'.$scope);
    }

    private function event(Ticket $ticket, ?User $actor = null): TicketRuleEventEnvelope
    {
        $actor ??= $this->actor;

        return new TicketRuleEventEnvelope(
            ticketId: (int) $ticket->id,
            eventKey: 'ticket.created',
            sourceChannel: 'manual',
            sourceAction: 'TicketRuleSchema2ActionExecutorTest',
            changedFields: ['created'],
            before: [],
            after: [],
            facts: [],
            initiatorType: 'user',
            initiatorId: $this->technician->id,
            automationActorId: (int) $actor->id,
            correlationUuid: (string) Str::uuid(),
            causationUuid: null,
            parentEventId: null,
            parentActionResultId: null,
            chainDepth: 0,
            occurredAt: CarbonImmutable::now(),
            fingerprint: $this->key('event-fingerprint-'.$ticket->id),
            idempotencyKey: $this->key('event-delivery-'.$ticket->id),
        );
    }

    private function failureCode(callable $callback): string
    {
        try {
            $callback();
            $this->fail('The schema 2 action should have failed closed.');
        } catch (TicketRuleActionFailure $failure) {
            return $failure->reasonCode;
        }
    }

    private function sla(string $name): Sla
    {
        return Sla::query()->create([
            'name' => $name,
            'description' => $name.' policy',
            'is_default' => false,
            'low_firstResponse' => 8,
            'low_firstResponse_type' => 'hours',
            'low_onsite' => 32,
            'low_onsite_type' => 'hours',
            'medium_firstResponse' => 4,
            'medium_firstResponse_type' => 'hours',
            'medium_onsite' => 16,
            'medium_onsite_type' => 'hours',
            'high_firstResponse' => 1,
            'high_firstResponse_type' => 'hours',
            'high_onsite' => 4,
            'high_onsite_type' => 'hours',
            'created_by_user_id' => $this->actor->id,
        ]);
    }
}
