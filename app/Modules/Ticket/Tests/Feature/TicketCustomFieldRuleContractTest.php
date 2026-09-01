<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Models\CustomFieldValue;
use App\Modules\CustomField\Support\CustomFieldModelRegistry;
use App\Modules\Ticket\Actions\SyncTicketCustomFieldValues;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Services\TicketCustomFieldTargetValidator;
use App\Modules\Ticket\Services\TicketRulePublishedDefinitionValidator;
use App\Modules\Ticket\Services\TicketRuleSchema2ActionExecutor;
use App\Modules\Ticket\Services\TicketRuleSchema2ConditionEvaluator;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleEventEnvelope;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketCustomFieldRuleContractTest extends TestCase
{
    use RefreshDatabase;

    private User $human;

    private User $automation;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_unique(['ticket.create', ...TicketRuleAutomationActor::PERMISSIONS]) as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->human = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->human->givePermissionTo(['ticket.create', 'ticket.update']);
        $this->automation = app(TicketRuleAutomationActor::class)->resolve();
        config()->set('ticket_rules.v2_enabled', false);
    }

    #[Test]
    public function alias_mutation_trigger_and_typed_conditions_keep_durable_evidence_redacted(): void
    {
        $this->enable();
        $models = app(CustomFieldModelRegistry::class);
        $this->assertSame(Ticket::class, $models->classFor('ticket'));
        $this->assertSame('ticket', $models->aliasFor(Ticket::class));

        $text = $this->field('private_text', ['model_type' => 'ticket']);
        $number = $this->field('risk_score', ['field_type' => CustomFieldDefinition::TYPE_NUMBER]);
        $date = $this->field('visit_date', ['field_type' => CustomFieldDefinition::TYPE_DATE]);
        $datetime = $this->field('visit_at', ['field_type' => CustomFieldDefinition::TYPE_DATETIME]);
        $checkbox = $this->field('requires_review', ['field_type' => CustomFieldDefinition::TYPE_CHECKBOX]);
        $multiselect = $this->field('affected_areas', [
            'field_type' => CustomFieldDefinition::TYPE_MULTISELECT,
            'options' => ['Billing', 'Operations'],
        ]);
        $ticket = Ticket::factory()->create();
        CustomFieldValue::query()->create([
            'custom_field_definition_id' => $text->id,
            'model_type' => 'ticket',
            'model_id' => $ticket->id,
            'value_text' => 'Old private value',
        ]);

        $event = app(SyncTicketCustomFieldValues::class)->handle(
            $ticket,
            [
                $text->key => 'Customer-secret-alpha',
                $number->key => '42.5',
                $date->key => '2026-09-01',
                $datetime->key => '2026-09-01 10:30:00',
                $checkbox->key => true,
                $multiselect->key => ['Billing', 'Operations'],
            ],
            $this->human,
            'ui',
            ['suppress_rule_dispatch' => true],
        )->event;
        $this->assertSame(TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED, $event?->eventKey);
        $this->assertSame('Old private value', data_get($event?->safeFacts, 'custom_fields.'.$text->id.'.before'));
        $this->assertSame('Customer-secret-alpha', data_get($event?->safeFacts, 'custom_fields.'.$text->id.'.after'));
        $this->assertDatabaseCount('custom_field_values', 6);

        $history = TicketEvent::query()->where('ticket_id', $ticket->id)
            ->where('type', 'custom_fields_changed')->sole();
        $durable = json_encode([$history->before, $history->after, $event?->before, $event?->after], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Old private value', $durable);
        $this->assertStringNotContainsString('Customer-secret-alpha', $durable);

        $noChange = app(SyncTicketCustomFieldValues::class)->handle(
            $ticket,
            [$text->key => 'Customer-secret-alpha'],
            $this->human,
            'ui',
            ['suppress_rule_dispatch' => true],
        );
        $this->assertNull($noChange->event);
        $this->assertSame(1, $ticket->events()->where('type', 'custom_fields_changed')->count());

        $targets = app(TicketCustomFieldTargetValidator::class);
        $triggers = app(TicketRuleTriggerRegistry::class);
        $filters = $triggers->canonicalizeFilters(
            TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED,
            ['targets' => [$targets->targetFor($text)], 'directions' => ['changed']],
        );
        $this->assertTrue($filters['valid']);
        $this->assertTrue($triggers->isRelevant(
            TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED,
            $filters['filters'],
            ['event_key' => $event?->eventKey, 'facts' => $event?->safeFacts],
        ));

        $conditions = [
            $this->condition(TicketCustomFieldTargetValidator::CURRENT, $text, 'contains', 'secret-alpha'),
            $this->condition(TicketCustomFieldTargetValidator::CURRENT, $number, 'greater_than', 40),
            $this->condition(TicketCustomFieldTargetValidator::CHANGED, $text, 'equals', true),
            $this->condition(TicketCustomFieldTargetValidator::CURRENT, $date, 'after_or_equal', '2026-08-31'),
            $this->condition(TicketCustomFieldTargetValidator::CURRENT, $datetime, 'before', '2026-09-02 00:00:00'),
            $this->condition(TicketCustomFieldTargetValidator::CURRENT, $checkbox, 'equals', true),
            $this->condition(TicketCustomFieldTargetValidator::CURRENT, $multiselect, 'contains', 'Operations'),
            $this->condition(TicketCustomFieldTargetValidator::CURRENT, $multiselect, 'intersects', ['Billing']),
            $this->condition(TicketCustomFieldTargetValidator::PRESENT, $text, 'equals', true),
            $this->condition(TicketCustomFieldTargetValidator::BEFORE, $text, 'equals', 'Old private value'),
            $this->condition(TicketCustomFieldTargetValidator::AFTER, $text, 'starts_with', 'Customer'),
        ];
        $evaluation = app(TicketRuleSchema2ConditionEvaluator::class)->evaluate([
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'conditions' => [
                'mode' => 'grouped',
                'match' => 'ALL',
                'groups' => [['match' => 'ALL', 'conditions' => $conditions]],
            ],
        ], ['ticket_id' => $ticket->id] + ($event?->safeFacts ?? []));
        $this->assertTrue($evaluation['valid']);
        $this->assertTrue($evaluation['passed']);
        $evidence = json_encode($evaluation, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Customer-secret-alpha', $evidence);
        $this->assertStringNotContainsString('Old private value', $evidence);
    }

    #[Test]
    public function publication_and_set_clear_actions_fail_closed_without_changing_assignment_semantics(): void
    {
        foreach (['ui_write', 'api_write', 'rule_trigger', 'rule_action'] as $gate) {
            $this->assertFalse(config('ticket_rules.capabilities.custom_fields.'.$gate));
        }
        $this->enable();

        $field = $this->field('route_choice', [
            'field_type' => CustomFieldDefinition::TYPE_SELECT,
            'options' => ['Primary', 'Secondary'],
        ]);
        $targets = app(TicketCustomFieldTargetValidator::class);
        $target = $targets->targetFor($field);
        $visibilityPermission = Permission::findOrCreate('ticket.custom_fields.audit.view', 'web');
        $restricted = $this->field('audit_only', [
            'view_permission' => $visibilityPermission->name,
        ]);
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->givePermissionTo($visibilityPermission);

        $this->assertFalse($targets->canViewDefinitionId($restricted->id, $this->human));
        $this->assertFalse($targets->canViewDefinitionId($restricted->id, $this->human));
        $this->assertTrue($targets->canViewDefinitionId($restricted->id, $viewer));
        $this->assertFalse($targets->canViewDefinitionId(0, $viewer));
        $definition = $this->published($target);
        $validator = app(TicketRulePublishedDefinitionValidator::class);
        $validated = $validator->validateForPublication($definition);
        $this->assertSame('valid', $validated['status'], json_encode($validated, JSON_THROW_ON_ERROR));

        config()->set('ticket_rules.capabilities.custom_fields.rule_trigger', false);
        $this->assertSame('trigger_capability_disabled', $validator->validateForPublication($definition)['reason_code']);
        config()->set('ticket_rules.capabilities.custom_fields.rule_trigger', true);
        $field->forceFill(['options' => ['Primary', 'Tertiary']])->save();
        $this->assertSame('custom_field_target_options_changed', $validator->validateForPublication($definition)['reason_code']);
        $field->forceFill(['options' => ['Primary', 'Secondary']])->save();

        $ticket = Ticket::factory()->create();
        $executor = app(TicketRuleSchema2ActionExecutor::class);
        $action = [
            'type' => TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
            'input' => ['target' => $target, 'value' => 'Secondary'],
        ];
        $snapshot = json_encode($executor->snapshot($action), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Secondary', $snapshot);
        $this->assertStringContainsString('sha256', $snapshot);

        $applied = $executor->handle(
            $ticket,
            $action,
            $this->automation,
            $this->event($ticket),
            true,
            hash('sha256', 'set-custom-field'),
        );
        $this->assertSame('succeeded', $applied['status']);
        $this->assertFalse($applied['assignment_decision']);
        $this->assertSame(TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED, $applied['derived_events'][0]->eventKey);

        $repeat = $executor->handle(
            $ticket,
            $action,
            $this->automation,
            $this->event($ticket),
            true,
            hash('sha256', 'repeat-custom-field'),
        );
        $this->assertSame('no_change', $repeat['status']);

        $cleared = $executor->handle(
            $ticket,
            ['type' => TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD, 'input' => ['target' => $target]],
            $this->automation,
            $this->event($ticket),
            true,
            hash('sha256', 'clear-custom-field'),
        );
        $this->assertSame('succeeded', $cleared['status']);

        $providers = app(TicketRuleActionProviderRegistry::class)->definitions();
        $this->assertTrue($providers['set_custom_field']['idempotency']['retryable']);
        $this->assertFalse($providers['set_custom_field']['assignment_decision']);
        $this->assertSame('queue_routing_group', $providers['set_queue']['assignment_concept']);
        $this->assertSame('individual_owner', $providers['assign_owner']['assignment_concept']);
        $this->assertArrayNotHasKey('assign_team', $providers);
    }

    private function field(string $key, array $attributes = []): CustomFieldDefinition
    {
        return CustomFieldDefinition::query()->create(array_merge([
            'model_type' => Ticket::class,
            'key' => $key,
            'label' => Str::headline($key),
            'field_type' => CustomFieldDefinition::TYPE_TEXT,
            'visible_in_ui' => true,
            'editable_in_ui' => true,
            'editable_via_api' => true,
            'searchable' => false,
            'unique_per_model' => false,
            'required' => false,
            'admin_only' => false,
            'active' => true,
        ], $attributes));
    }

    private function condition(string $name, CustomFieldDefinition $field, string $operator, mixed $value): array
    {
        return [
            'field' => $name,
            'target' => app(TicketCustomFieldTargetValidator::class)->targetFor($field),
            'operator' => $operator,
            'value' => $value,
        ];
    }

    private function published(array $target): array
    {
        return [
            'schema_version' => 2,
            'trigger' => TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED,
            'trigger_filters' => ['targets' => [$target]],
            'conditions' => ['mode' => 'always', 'match' => 'ALL', 'groups' => []],
            'then_actions' => [[
                'type' => TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
                'input' => ['target' => $target, 'value' => 'Secondary'],
            ]],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 20],
        ];
    }

    private function enable(): void
    {
        foreach (['ui_write', 'api_write', 'rule_trigger', 'rule_action'] as $gate) {
            config()->set('ticket_rules.capabilities.custom_fields.'.$gate, true);
        }
        $triggers = (array) config('ticket_rules.capabilities.triggers', []);
        $triggers[TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED] = true;
        config()->set('ticket_rules.capabilities.triggers', $triggers);

        $actions = (array) config('ticket_rules.capabilities.actions', []);
        $actions[TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD] = true;
        $actions[TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD] = true;
        config()->set('ticket_rules.capabilities.actions', $actions);
    }

    private function event(Ticket $ticket): TicketRuleEventEnvelope
    {
        $key = hash('sha256', 'custom-field-event-'.$ticket->id);

        return new TicketRuleEventEnvelope(
            ticketId: (int) $ticket->id,
            eventKey: TicketRuleTriggerRegistry::CREATED,
            sourceChannel: 'test',
            sourceAction: self::class,
            changedFields: ['created'],
            before: [],
            after: [],
            facts: ['ticket_id' => (int) $ticket->id],
            initiatorType: 'user',
            initiatorId: (int) $this->human->id,
            automationActorId: (int) $this->automation->id,
            correlationUuid: (string) Str::uuid(),
            causationUuid: null,
            parentEventId: null,
            parentActionResultId: null,
            chainDepth: 0,
            occurredAt: CarbonImmutable::now(),
            fingerprint: $key,
            idempotencyKey: $key,
        );
    }
}
