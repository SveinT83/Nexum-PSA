<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Notification\Notifications\TicketCommentAdded;
use App\Modules\Relationship\Actions\SyncTicketMessageToRelationship;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Actions\AddTicketMessage;
use App\Modules\Ticket\Actions\ApplyTicketWorkflowActionTrigger;
use App\Modules\Ticket\Actions\AssignTicketOwner;
use App\Modules\Ticket\Actions\ChangeTicketStatus;
use App\Modules\Ticket\Actions\MutateTicketTags;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Actions\UpdateTicketFields;
use App\Modules\Ticket\Jobs\SendTicketReplyEmail;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleEvent;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Services\TicketRuleMessageMutationEventFactory;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleMutationEvent;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketCanonicalMutationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ticket_rules.v2_enabled', false);
        Permission::findOrCreate('ticket.update', 'web');
        Permission::findOrCreate('ticket.assign', 'web');
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo(['ticket.update', 'ticket.assign']);
    }

    #[Test]
    public function tag_mutation_records_exact_delta_and_suppresses_no_change(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => $this->actor->id]);
        $first = Tag::query()->create(['name' => 'First Tag', 'slug' => 'first-tag', 'active' => true]);
        $second = Tag::query()->create(['name' => 'Second Tag', 'slug' => 'second-tag', 'active' => true]);
        $action = app(MutateTicketTags::class);

        $added = $action->handle($ticket, [$second->id, (string) $first->id, $second->id], [], $this->actor);

        $this->assertTrue($added->changed());
        $this->assertSame([$first->id, $second->id], $added->event->safeFacts['added_tag_ids']);
        $this->assertSame([], $added->event->safeFacts['removed_tag_ids']);
        $this->assertSame([$first->id, $second->id], $ticket->fresh()->tags()->orderBy('tags.id')->pluck('tags.id')->all());
        $this->assertSame(1, TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'tags_changed')->count());

        $noChange = $action->handle($ticket->fresh(), [$first->id], [], $this->actor);

        $this->assertFalse($noChange->changed());
        $this->assertSame(1, TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'tags_changed')->count());

        $replaced = $action->replace($ticket->fresh(), [$second->id], $this->actor);

        $this->assertTrue($replaced->changed());
        $this->assertSame([], $replaced->event->safeFacts['added_tag_ids']);
        $this->assertSame([$first->id], $replaced->event->safeFacts['removed_tag_ids']);
        $this->assertSame([$second->id], $ticket->fresh()->tags()->pluck('tags.id')->all());
    }

    #[Test]
    public function trusted_tag_import_requires_an_allowlisted_source_and_preserves_provenance(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => null]);
        $tag = Tag::query()->create(['name' => 'Inbound Tag', 'slug' => 'inbound-tag', 'active' => true]);
        $action = app(MutateTicketTags::class);

        try {
            $action->addFromTrustedSource($ticket, [$tag->id], [
                'source_channel' => 'customer_portal',
                'source_action' => 'UnapprovedSource',
                'delivery_identity' => 'unapproved:1',
            ]);
            $this->fail('Unapproved trusted-import sources must fail closed.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('approved source', $exception->getMessage());
        }

        $result = \Illuminate\Support\Facades\DB::transaction(
            fn () => $action->addFromTrustedSource($ticket, [$tag->id], [
                'source_channel' => 'email',
                'source_action' => 'LinkInboundEmailToTicket',
                'delivery_identity' => 'inbound-email-tags:1',
            ]),
        );

        $this->assertTrue($result->changed());
        $this->assertSame([$tag->id], $result->event->safeFacts['added_tag_ids']);
        $this->assertSame('email', $result->event->sourceChannel);
        $this->assertNull($ticket->fresh()->updated_by);
    }

    #[Test]
    public function tag_mutation_rejects_inactive_and_cross_domain_targets(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => $this->actor->id]);
        $inactive = Tag::query()->create(['name' => 'Inactive Tag', 'slug' => 'inactive-tag', 'active' => false]);

        try {
            app(MutateTicketTags::class)->handle($ticket, [$inactive->id], [], $this->actor);
            $this->fail('Inactive tags must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tag_ids', $exception->errors());
        }

        $foreign = Tag::query()->create(['name' => 'Foreign Tag', 'slug' => 'foreign-tag', 'active' => true]);
        $ticket->tags()->attach($foreign->id, ['module' => 'email']);

        try {
            app(MutateTicketTags::class)->handle($ticket->fresh(), [], [$foreign->id], $this->actor);
            $this->fail('Cross-domain Ticket pivots must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('different domain', $exception->errors()['tag_ids'][0]);
        }

        $this->assertTrue($ticket->fresh()->tags()->whereKey($foreign->id)->exists());
        $this->assertSame(0, TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'tags_changed')->count());
    }

    #[Test]
    public function explicit_owner_action_keeps_queue_and_rejects_ineligible_targets(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => null]);
        $owner = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $queueId = (int) $ticket->queue_id;
        $action = app(AssignTicketOwner::class);

        $assigned = $action->handle($ticket, $owner->id, $this->actor);

        $this->assertTrue($assigned->changed());
        $this->assertSame(['owner_assigned'], $assigned->event->classification['assignment_changes']);
        $this->assertSame($owner->id, (int) $ticket->fresh()->owner_id);
        $this->assertSame($queueId, (int) $ticket->fresh()->queue_id);
        $this->assertSame($this->actor->id, (int) $ticket->fresh()->updated_by);

        $noChange = $action->handle($ticket->fresh(), $owner->id, $this->actor);
        $this->assertFalse($noChange->changed());

        $unassigned = $action->handle($ticket->fresh(), null, $this->actor);
        $this->assertSame(['owner_unassigned'], $unassigned->event->classification['assignment_changes']);
        $this->assertNull($ticket->fresh()->owner_id);
        $this->assertSame($queueId, (int) $ticket->fresh()->queue_id);

        $disabled = User::factory()->create(['status' => User::STATUS_DISABLED]);
        $this->expectException(ValidationException::class);
        $action->handle($ticket->fresh(), $disabled->id, $this->actor);
    }

    #[Test]
    public function owner_assignment_reauthorizes_the_initiating_actor(): void
    {
        $ticket = Ticket::factory()->create();
        $owner = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->expectException(ValidationException::class);
        app(AssignTicketOwner::class)->handle($ticket, $owner->id, $unauthorized);
    }

    #[Test]
    public function managed_actor_message_is_internal_system_authored_and_retains_no_raw_body_in_event(): void
    {
        Notification::fake();
        $ticket = Ticket::factory()->create(['owner_id' => $this->actor->id]);
        $systemActor = User::factory()->create([
            'status' => User::STATUS_DISABLED,
            'is_system_actor' => true,
            'system_actor_key' => 'test_ticket_rule_actor',
        ]);
        $rawBody = 'Private internal diagnostic with customer data.';
        $this->mock(ApplyTicketWorkflowActionTrigger::class)->shouldNotReceive('handle');

        $message = app(AddTicketMessage::class)->handle($ticket, [
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => $rawBody,
            'notify_user_id' => $this->actor->id,
            '_author_type' => 'external',
        ], $systemActor);

        $this->assertSame('system', $message->author_type);
        $this->assertSame('internal_note', $message->type);
        $this->assertSame('internal', $message->visibility);
        $this->assertSame($systemActor->id, (int) $message->author_id);
        $this->assertArrayNotHasKey('notify_user_id', $message->metadata ?? []);

        $history = TicketEvent::query()
            ->where('ticket_id', $ticket->id)
            ->where('type', 'message_added')
            ->latest('id')
            ->firstOrFail();
        $this->assertStringNotContainsString($rawBody, json_encode($history->after, JSON_THROW_ON_ERROR));
        Notification::assertNotSentTo($this->actor, TicketCommentAdded::class);
    }

    #[Test]
    public function message_assignment_claim_is_default_and_can_be_suppressed_for_creation_composition(): void
    {
        app(TicketRuleAutomationActor::class)->resolve();
        config()->set('ticket_rules.v2_enabled', true);
        config()->set('ticket_rules.allow_sqlite_mutations_for_tests', true);
        config()->set('ticket_rules.capabilities.triggers', array_replace(
            (array) config('ticket_rules.capabilities.triggers', []),
            [
                TicketRuleTriggerRegistry::MESSAGE_ADDED => true,
                TicketRuleTriggerRegistry::ASSIGNMENT_CHANGED => true,
            ],
        ));
        DB::table('ticket_rule_authority_fences')
            ->where('scope', TicketRuleAuthorityFence::SCOPE)
            ->update(['runtime_authority' => TicketRuleAuthorityFence::AUTHORITY_V2]);
        $this->publishAssignmentRule();

        $defaultTicket = Ticket::factory()->create(['owner_id' => null]);
        $suppressedTicket = Ticket::factory()->create(['owner_id' => null]);
        $action = app(AddTicketMessage::class);

        $action->handle($defaultTicket, [
            'body' => 'Default claim behavior.',
            'suppress_workflow_trigger' => true,
        ], $this->actor);
        $action->handle($suppressedTicket, [
            'body' => 'Creation composition must stay unassigned.',
            'suppress_workflow_trigger' => true,
            '_suppress_ticket_rule_dispatch' => true,
            '_suppress_assignment_claim' => true,
        ], $this->actor);

        $this->assertSame($this->actor->id, (int) $defaultTicket->fresh()->owner_id);
        $this->assertNull($suppressedTicket->fresh()->owner_id);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $defaultTicket->id,
            'type' => 'assigned',
            'actor_id' => $this->actor->id,
        ]);
        $this->assertDatabaseMissing('ticket_events', [
            'ticket_id' => $suppressedTicket->id,
            'type' => 'assigned',
        ]);

        $run = TicketRuleRun::query()->where('ticket_id', $defaultTicket->id)->sole();
        $event = TicketRuleEvent::query()->where('run_id', $run->id)->sole();
        $this->assertSame(TicketRuleTriggerRegistry::MESSAGE_ADDED, $event->event_key);
        $this->assertSame(['message_id', 'owner_id'], $event->changed_fields_json);
        $this->assertSame($this->actor->id, (int) $event->after_json['owner_id']);
        $this->assertSame(1, TicketRuleRun::query()->count());
    }

    #[Test]
    public function status_change_and_owner_claim_share_one_root_run(): void
    {
        app(TicketRuleAutomationActor::class)->resolve();
        config()->set('ticket_rules.v2_enabled', true);
        config()->set('ticket_rules.allow_sqlite_mutations_for_tests', true);
        config()->set('ticket_rules.capabilities.triggers', array_replace(
            (array) config('ticket_rules.capabilities.triggers', []),
            [
                TicketRuleTriggerRegistry::STATUS_CHANGED => true,
                TicketRuleTriggerRegistry::ASSIGNMENT_CHANGED => true,
            ],
        ));
        DB::table('ticket_rule_authority_fences')
            ->where('scope', TicketRuleAuthorityFence::SCOPE)
            ->update(['runtime_authority' => TicketRuleAuthorityFence::AUTHORITY_V2]);
        $this->publishAssignmentRule();

        $new = TicketStatus::query()->create([
            'name' => 'New',
            'slug' => 'canonical-new',
            'state' => 'new',
            'is_default' => false,
            'is_closed' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $inProgress = TicketStatus::query()->create([
            'name' => 'In progress',
            'slug' => 'canonical-in-progress',
            'state' => 'in_progress',
            'is_default' => false,
            'is_closed' => false,
            'is_active' => true,
            'sort_order' => 20,
        ]);
        $ticket = Ticket::factory()->create([
            'status_id' => $new->id,
            'owner_id' => null,
        ]);

        app(ChangeTicketStatus::class)->handle(
            $ticket,
            $inProgress,
            $this->actor,
            enforceWorkflow: false,
            syncRelationship: false,
            notifyCustomerPortal: false,
            notifyOwner: false,
        );

        $run = TicketRuleRun::query()->where('ticket_id', $ticket->id)->sole();
        $event = TicketRuleEvent::query()->where('run_id', $run->id)->sole();
        $this->assertSame($this->actor->id, (int) $ticket->fresh()->owner_id);
        $this->assertSame($inProgress->id, (int) $ticket->fresh()->status_id);
        $this->assertSame(TicketRuleTriggerRegistry::STATUS_CHANGED, $event->event_key);
        $this->assertSame(['owner_id', 'status_id'], $event->changed_fields_json);
        $this->assertSame($this->actor->id, (int) $event->after_json['owner_id']);
        $this->assertSame($inProgress->id, (int) $event->after_json['status_id']);
        $this->assertSame(1, (int) $run->counters_json['evaluated_rules']);
        $this->assertSame(1, TicketRuleRun::query()->count());
    }

    #[Test]
    public function internal_message_author_type_is_strictly_allowlisted(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => $this->actor->id]);
        $action = app(AddTicketMessage::class);

        foreach (['user', 'portal_user', 'external'] as $authorType) {
            $message = $action->handle($ticket, [
                'body' => 'Allowlisted author provenance.',
                '_author_type' => $authorType,
                'suppress_workflow_trigger' => true,
                'suppress_notifications' => true,
                '_suppress_assignment_claim' => true,
                '_suppress_ticket_rule_dispatch' => true,
            ], $this->actor);
            $this->assertSame($authorType, $message->author_type);
        }

        try {
            $action->handle($ticket, [
                'body' => 'Must not impersonate a system actor.',
                '_author_type' => 'system',
                'suppress_workflow_trigger' => true,
                'suppress_notifications' => true,
                '_suppress_assignment_claim' => true,
                '_suppress_ticket_rule_dispatch' => true,
            ], $this->actor);
            $this->fail('Only managed system actors may use system authorship.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('_author_type', $exception->errors());
        }
    }

    #[Test]
    public function inbound_reply_suppression_prevents_outbound_delivery_and_customer_self_notification(): void
    {
        Queue::fake();
        $this->mock(SyncTicketMessageToRelationship::class)->shouldNotReceive('handle');
        $this->mock(SendCustomerPortalNotification::class)->shouldNotReceive('handle');

        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'portal_visible_at' => now(),
            'owner_id' => $this->actor->id,
        ]);

        $message = app(AddTicketMessage::class)->handle($ticket, [
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'Inbound customer reply.',
            '_author_type' => 'portal_user',
            '_suppress_reply_delivery' => true,
            'suppress_workflow_trigger' => true,
            'suppress_notifications' => true,
            '_suppress_assignment_claim' => true,
            '_suppress_ticket_rule_dispatch' => true,
        ], $this->actor);

        $this->assertSame('portal_user', $message->author_type);
        Queue::assertNotPushed(SendTicketReplyEmail::class);
    }

    #[Test]
    public function normalized_event_rejects_raw_message_body_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TicketRuleMutationEvent::make(
            ticketId: 1,
            eventKey: 'ticket.message_added',
            changedFields: ['message_id'],
            before: [],
            after: ['message_id' => 1],
            safeFacts: ['body' => 'must not survive'],
            classification: [],
            sourceChannel: 'tech',
            sourceAction: 'test',
            deliveryIdentity: 'test-delivery',
        );
    }

    #[Test]
    public function inbound_message_factory_emits_safe_email_relevance_without_raw_content(): void
    {
        $ticket = Ticket::factory()->create(['channel' => 'email']);
        $message = TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'author_type' => 'contact',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'subject' => 'Private inbound subject',
            'body' => 'Private inbound body',
        ]);

        $event = app(TicketRuleMessageMutationEventFactory::class)->make($ticket, $message, [
            '_event_source_channel' => 'email',
            '_event_source_action' => 'LinkInboundEmailToTicket',
            '_delivery_key' => 'inbound-email-message:42',
        ]);

        $this->assertSame('ticket.message_added', $event->eventKey);
        $this->assertSame('customer_reply', $event->safeFacts['message_type']);
        $this->assertSame('email', $event->sourceChannel);
        $this->assertSame('LinkInboundEmailToTicket', $event->sourceAction);
        $this->assertSame('inbound-email-message:42', $event->deliveryIdentity);
        $encoded = json_encode($event->coordinatorContext(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Private inbound body', $encoded);
        $this->assertStringNotContainsString('Private inbound subject', $encoded);
    }

    #[Test]
    public function update_fields_exposes_one_actual_change_event_and_suppresses_no_op(): void
    {
        $ticket = Ticket::factory()->create([
            'owner_id' => $this->actor->id,
            'subject' => 'Before',
        ]);
        $action = app(UpdateTicketFields::class);

        $changed = $action->handleWithResult($ticket, ['subject' => 'After'], $this->actor);

        $this->assertTrue($changed->changed());
        $this->assertSame(['subject'], $changed->event->changedFields);
        $this->assertSame(['subject' => 'Before'], $changed->event->before);
        $this->assertSame(['subject' => 'After'], $changed->event->after);
        $this->assertSame(1, TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'fields_updated')->count());

        $noChange = $action->handleWithResult($ticket->fresh(), ['subject' => 'After'], $this->actor);

        $this->assertFalse($noChange->changed());
        $this->assertSame(1, TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'fields_updated')->count());
    }

    #[Test]
    public function schema_two_standard_fields_delegate_sla_with_rule_source_and_new_priority_clock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 09:00:00'));

        try {
            $high = TicketPriority::query()->create([
                'name' => 'Rule High',
                'slug' => 'rule-high',
                'level' => 1,
                'is_default' => false,
                'is_active' => true,
                'sort_order' => 90,
            ]);
            $type = TicketType::query()->create([
                'name' => 'Rule Type',
                'slug' => 'rule-type',
                'is_active' => true,
                'is_default' => false,
                'sort_order' => 90,
            ]);
            $sla = $this->createSla('Rule SLA');
            $systemActor = User::factory()->create([
                'status' => User::STATUS_DISABLED,
                'is_system_actor' => true,
                'system_actor_key' => 'test_sla_rule_actor',
            ]);
            $ticket = Ticket::factory()->create([
                'owner_id' => $this->actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $action = app(UpdateTicketFields::class);
            $input = [
                'ticket_type_id' => $type->id,
                'priority_id' => $high->id,
                'sla_id' => $sla->id,
                'impact' => 5,
                'urgency' => 4,
                '_sla_source' => 'ticket_rule',
                '_suppress_ticket_rule_dispatch' => true,
            ];

            $result = $action->handleWithResult($ticket, $input, $systemActor);
            $ticket->refresh();

            $this->assertTrue($result->changed());
            $this->assertSame($type->id, (int) $ticket->ticket_type_id);
            $this->assertSame($high->id, (int) $ticket->priority_id);
            $this->assertSame($sla->id, (int) $ticket->sla_id);
            $this->assertSame('ticket_rule', $ticket->sla_source);
            $this->assertSame($sla->id, (int) $ticket->sla_source_id);
            $this->assertSame('high', $ticket->sla_snapshot['priority_band']);
            $this->assertSame('2026-08-26 10:00:00', $ticket->first_response_due_at->toDateTimeString());
            $this->assertSame(5, (int) $ticket->impact);
            $this->assertSame(4, (int) $ticket->urgency);
            $this->assertSame(1, $ticket->events()->where('type', 'sla_applied')->count());
            $this->assertSame(1, $ticket->events()->where('type', 'fields_updated')->count());

            $noChange = $action->handleWithResult($ticket->fresh(), $input, $systemActor);

            $this->assertFalse($noChange->changed());
            $this->assertSame(1, $ticket->events()->where('type', 'sla_applied')->count());
            $this->assertSame(1, $ticket->events()->where('type', 'fields_updated')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function invalid_sla_rolls_back_the_composite_field_update(): void
    {
        $ticket = Ticket::factory()->create([
            'owner_id' => $this->actor->id,
            'subject' => 'Original subject',
        ]);

        try {
            app(UpdateTicketFields::class)->handleWithResult($ticket, [
                'subject' => 'Must roll back',
                'sla_id' => 999999,
            ], $this->actor);
            $this->fail('An unavailable SLA must fail the whole composite update.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sla_id', $exception->errors());
        }

        $this->assertSame('Original subject', $ticket->fresh()->subject);
        $this->assertSame(0, $ticket->events()->whereIn('type', ['sla_applied', 'fields_updated'])->count());
    }

    private function publishAssignmentRule(): TicketRuleVersion
    {
        $definition = [
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => TicketRuleTriggerRegistry::ASSIGNMENT_CHANGED,
            'trigger_filters' => [
                'changes' => ['owner_assigned'],
            ],
            'conditions' => [
                'mode' => 'always',
                'match' => 'ALL',
                'groups' => [],
            ],
            'then_actions' => [],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 10],
        ];
        $checksum = TicketRuleStableJson::checksum($definition);
        $rule = TicketRule::query()->create([
            'name' => 'Owner assignment observation',
            'description' => 'Composite message and assignment event fixture.',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => 10,
            'is_active' => true,
            'stop_processing' => false,
            'conditions_json' => [],
            'actions_json' => [],
        ]);
        $version = TicketRuleVersion::query()->create([
            'ticket_rule_id' => $rule->id,
            'version_number' => 1,
            'status' => TicketRuleVersion::STATUS_PUBLISHED,
            'definition_schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger_key' => TicketRuleTriggerRegistry::ASSIGNMENT_CHANGED,
            'weight' => 10,
            'stop_processing' => false,
            'name' => 'Owner assignment observation',
            'description' => 'Composite message and assignment event fixture.',
            'definition_json' => $definition,
            'definition_checksum' => $checksum,
            'source_is_active' => true,
            'source_trigger' => TicketRuleTriggerRegistry::ASSIGNMENT_CHANGED,
            'source_hit_count' => 0,
            'published_by' => $this->actor->id,
            'published_at' => now(),
            'provenance' => TicketRuleVersion::PROVENANCE_ADMIN_PUBLISH,
            'provenance_batch_uuid' => (string) Str::uuid(),
            'provenance_recorded_at' => now(),
        ]);
        DB::table('ticket_rules')->where('id', $rule->id)->update([
            'lifecycle_status' => TicketRule::LIFECYCLE_PUBLISHED,
            'published_version_id' => $version->id,
            'published_by' => $this->actor->id,
            'published_at' => now(),
            'definition_schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'definition_checksum' => $checksum,
            'compatibility_status' => TicketRule::COMPATIBILITY_ELIGIBLE,
            'compatibility_checked_at' => now(),
        ]);

        return $version->refresh();
    }

    private function createSla(string $name): Sla
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
