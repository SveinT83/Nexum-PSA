<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Models\Item as StorageItem;
use App\Modules\Storage\Models\Warehouse as StorageWarehouse;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreManualTicketCostEntry;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Actions\StoreTicketPlannedLine;
use App\Modules\Ticket\Livewire\Admin\WorkflowEditor;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketPlannedLine;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Models\TicketWorkflowEvidence;
use App\Modules\Ticket\Models\TicketWorkflowHistory;
use App\Modules\Ticket\Models\TicketWorkflowReview;
use App\Modules\Ticket\Services\TicketWorkflowDefinitionService;
use App\Modules\Ticket\Services\TicketWorkflowRequirementEvaluator;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketWorkflowV3Test extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Tech', 'web');
        Role::findOrCreate('Admin', 'web');
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        app(EnsureTicketDefaults::class)->handle();
    }

    #[Test]
    public function action_builder_adds_and_removes_only_selected_state_actions(): void
    {
        \Livewire\Livewire::test(WorkflowEditor::class, [
            'statuses' => \App\Modules\Ticket\Models\TicketStatus::query()->orderBy('sort_order')->get(),
            'triggerActions' => \App\Modules\Ticket\Support\TicketAction::definitions(),
        ])
            ->assertSet('states.0.action_policy.add_actual_cost.mode', 'inherit')
            ->assertSeeHtml('bi bi-plus')
            ->assertDontSeeHtml('name="states[0][action_policy][add_actual_cost][mode]"')
            ->set('actionToAdd.0', 'add_actual_cost')
            ->call('addAction', 0)
            ->assertSet('states.0.action_policy.add_actual_cost.mode', 'available')
            ->assertSeeHtml('name="states[0][action_policy][add_actual_cost][mode]"')
            ->call('removeAction', 0, 'add_actual_cost')
            ->assertSet('states.0.action_policy.add_actual_cost.mode', 'inherit')
            ->assertDontSeeHtml('name="states[0][action_policy][add_actual_cost][mode]"');
    }

    #[Test]
    public function workflow_editor_defers_ordinary_fields_until_an_explicit_action(): void
    {
        \Livewire\Livewire::test(WorkflowEditor::class, [
            'statuses' => \App\Modules\Ticket\Models\TicketStatus::query()->orderBy('sort_order')->get(),
            'triggerActions' => \App\Modules\Ticket\Support\TicketAction::definitions(),
        ])
            ->call('addRequirementGroup', 'state', 0)
            ->assertSeeHtml('wire:model="states.0.name"')
            ->assertSeeHtml('wire:model="states.0.ticket_status_id"')
            ->assertSeeHtml('x-on:change="normalizeFact()"')
            ->assertSee('must be true')
            ->assertSee('must be false')
            ->assertDontSeeHtml('wire:model.live')
            ->assertDontSeeHtml('wire:model.blur')
            ->assertDontSeeHtml('wire:click="openState');
    }

    #[Test]
    public function workflow_editor_starts_with_all_steps_collapsed(): void
    {
        \Livewire\Livewire::test(WorkflowEditor::class, [
            'statuses' => \App\Modules\Ticket\Models\TicketStatus::query()->orderBy('sort_order')->get(),
            'triggerActions' => \App\Modules\Ticket\Support\TicketAction::definitions(),
        ])
            ->assertSet('openStateKey', null)
            ->assertSeeHtml('class="accordion-button w-auto flex-grow-1 collapsed"')
            ->assertDontSeeHtml('class="accordion-collapse collapse show"');
    }

    #[Test]
    public function steps_can_be_removed_from_the_collapsed_header_but_the_last_step_is_kept(): void
    {
        $component = \Livewire\Livewire::test(WorkflowEditor::class, [
            'statuses' => \App\Modules\Ticket\Models\TicketStatus::query()->orderBy('sort_order')->get(),
            'triggerActions' => \App\Modules\Ticket\Support\TicketAction::definitions(),
        ])
            ->assertDontSee('Remove step')
            ->call('addStateAfter', 0);

        $secondStateKey = $component->get('states.1.state_key');

        $component
            ->assertSee('Remove step')
            ->assertSeeHtml('data-workflow-remove-step="'.$secondStateKey.'"')
            ->assertSeeHtml('wire:click="removeState(1)"')
            ->call('removeState', 1)
            ->assertDontSeeHtml('data-workflow-remove-step');

        $this->assertCount(1, $component->get('states'));
        $this->assertCount(0, $component->get('transitions'));

        $component->call('removeState', 0);
        $this->assertCount(1, $component->get('states'));
    }

    #[Test]
    public function steps_are_added_below_the_source_and_buttons_can_return_to_an_earlier_step(): void
    {
        $component = \Livewire\Livewire::test(WorkflowEditor::class, [
            'statuses' => \App\Modules\Ticket\Models\TicketStatus::query()->orderBy('sort_order')->get(),
            'triggerActions' => \App\Modules\Ticket\Support\TicketAction::definitions(),
        ])
            ->assertDontSee('Reporting status for new step')
            ->assertSee('Add next step')
            ->assertSee('Next-step buttons')
            ->assertSeeHtml('wire:click="addStateAfter(0)"');

        $firstStateKey = $component->get('states.0.state_key');

        $component->call('addStateAfter', 0);

        $secondStateKey = $component->get('states.1.state_key');
        $states = $component->get('states');
        $transitions = $component->get('transitions');

        $this->assertCount(2, $states);
        $this->assertNotSame($firstStateKey, $secondStateKey);
        $this->assertCount(1, $transitions);
        $this->assertSame($firstStateKey, $transitions[0]['from_state_key']);
        $this->assertSame($secondStateKey, $transitions[0]['to_state_key']);
        $component
            ->assertSet('openStateKey', $secondStateKey)
            ->assertSeeHtml('name="states[1][ticket_status_id]"')
            ->assertSee('(earlier)')
            ->assertSeeHtml('wire:click="addTransition(1)"')
            ->set('transitionToAdd.1', $firstStateKey)
            ->call('addTransition', 1)
            ->assertSet('openStateKey', $secondStateKey)
            ->call('addRequirementGroup', 'state', 1)
            ->assertSet('openStateKey', $secondStateKey);
        $transitions = $component->get('transitions');
        $this->assertCount(2, $transitions);
        $this->assertSame($secondStateKey, $transitions[1]['from_state_key']);
        $this->assertSame($firstStateKey, $transitions[1]['to_state_key']);
    }

    #[Test]
    public function workflow_editor_can_add_and_remove_automatic_transition_triggers(): void
    {
        $component = \Livewire\Livewire::test(WorkflowEditor::class, [
            'statuses' => \App\Modules\Ticket\Models\TicketStatus::query()->orderBy('sort_order')->get(),
            'triggerActions' => TicketAction::definitions(),
        ])->call('addStateAfter', 0);

        $component
            ->assertSee('Automatic after action')
            ->assertSee('Any technician activity')
            ->set('transitionTriggerToAdd.0', TicketAction::ANY_TECHNICIAN_ACTIVITY)
            ->call('addTransitionTrigger', 0)
            ->assertSet('transitions.0.trigger_actions.0', TicketAction::ANY_TECHNICIAN_ACTIVITY)
            ->assertSeeHtml('name="transitions[0][trigger_actions][]"')
            ->call('removeTransitionTrigger', 0, TicketAction::ANY_TECHNICIAN_ACTIVITY)
            ->assertSet('transitions.0.trigger_actions', []);
    }

    #[Test]
    public function any_technician_activity_advances_only_after_transition_requirements_are_satisfied(): void
    {
        [$workflow, $version, $inProgress] = $this->activityWorkflow('activity-after-asset-gate', 'asset.linked');
        [$ticket, $client, $site] = $this->ticketWithClient($workflow);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk();
        $this->assertSame('intake', $ticket->refresh()->workflow_state_key);
        $this->assertSame(0, $ticket->workflowHistory()->count());

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.messages.store', $ticket), [
                'type' => 'internal_note',
                'visibility' => 'internal',
                'body' => 'Initial diagnostic note before an asset is linked.',
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $this->assertSame('intake', $ticket->refresh()->workflow_state_key);
        $this->assertDatabaseMissing('ticket_workflow_histories', [
            'ticket_id' => $ticket->id,
            'transition_key' => 'first-activity',
        ]);

        $asset = Asset::query()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'name' => 'Customer laptop',
            'type' => 'computer',
            'ip_type' => 'dhcp',
            'status' => 'online',
        ]);

        Sanctum::actingAs($this->tech, ['tickets.update']);
        $this->patchJson(route('api.v1.tickets.update', $ticket), ['asset_id' => $asset->id])
            ->assertOk()
            ->assertJsonPath('data.workflow_state_key', 'work')
            ->assertJsonPath('data.status_id', $inProgress->id);

        $this->assertDatabaseHas('ticket_workflow_histories', [
            'ticket_id' => $ticket->id,
            'workflow_version_id' => $version->id,
            'transition_key' => 'first-activity',
            'from_state_key' => 'intake',
            'to_state_key' => 'work',
        ]);
    }

    #[Test]
    public function timer_time_and_cost_actions_have_browser_api_parity_and_can_trigger_any_technician_activity(): void
    {
        [$workflow] = $this->activityWorkflow('api-technician-activity');
        Sanctum::actingAs($this->tech, ['tickets.actions']);

        [$timerTicket] = $this->ticketWithClient($workflow);
        $this->postJson(route('api.v1.tickets.timer.start', $timerTicket))
            ->assertOk()
            ->assertJsonPath('data.transitioned', true)
            ->assertJsonPath('data.ticket.workflow_state_key', 'work');
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $timerTicket->id,
            'type' => 'timer_started',
        ]);

        $rate = TimeRate::query()->create([
            'name' => 'Workflow API time',
            'slug' => 'workflow-api-time',
            'code' => 'WORKFLOW_API_TIME',
            'rate_type' => 'labor',
            'unit' => 'hour',
            'amount_ex_vat' => 1250,
            'currency' => 'NOK',
            'applies_without_contract' => true,
            'applies_with_contract' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        [$timeTicket] = $this->ticketWithClient($workflow);
        $this->postJson(route('api.v1.tickets.time-entries.store', $timeTicket), [
            'work_date' => '2026-07-17',
            'minutes' => 15,
            'rate_key' => 'global:'.$rate->id,
            'invoice_text' => 'Initial diagnosis.',
        ])
            ->assertCreated()
            ->assertJsonPath('ticket.workflow_state_key', 'work');

        [$costTicket] = $this->ticketWithClient($workflow);
        $this->postJson(route('api.v1.tickets.cost-entries.store', $costTicket), [
            'cost_mode' => 'manual',
            'item_name' => 'Diagnostic cable',
            'quantity' => 1,
            'unit_price_ex_vat' => 250,
            'currency' => 'NOK',
        ])
            ->assertCreated()
            ->assertJsonPath('ticket.workflow_state_key', 'work');

        [$webTimerTicket] = $this->ticketWithClient($workflow);
        $this->actingAs($this->tech)
            ->postJson(route('tech.tickets.timer.start', $webTimerTicket))
            ->assertOk()
            ->assertJsonPath('data.transitioned', true)
            ->assertJsonPath('data.workflow_state_key', 'work');

        $this->assertSame(4, \App\Modules\Ticket\Models\TicketWorkflowHistory::query()
            ->whereIn('ticket_id', [$timerTicket->id, $timeTicket->id, $costTicket->id, $webTimerTicket->id])
            ->where('transition_key', 'first-activity')
            ->count());
    }

    #[Test]
    public function internal_solution_uses_the_ticket_pinned_workflow_version_and_records_the_step(): void
    {
        $new = \App\Modules\Ticket\Models\TicketStatus::query()->where('slug', 'new')->firstOrFail();
        $inProgress = \App\Modules\Ticket\Models\TicketStatus::query()->where('slug', 'in-progress')->firstOrFail();
        $workflow = TicketWorkflow::query()->create([
            'name' => 'Pinned solution flow',
            'slug' => 'pinned-solution-flow',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 80,
        ]);
        $definition = [
            'states' => [
                $this->state('intake', $new->id, 'Intake', true),
                $this->state('work', $inProgress->id, 'Work'),
            ],
            'transitions' => [[
                'transition_key' => 'start-work',
                'from_state_key' => 'intake',
                'to_state_key' => 'work',
                'label' => 'Start work',
                'manual_enabled' => true,
                'trigger_actions' => [TicketAction::SEND_SOLUTION],
                'requirements' => ['match' => 'all', 'groups' => []],
                'sort_order' => 10,
            ]],
            'escalation_paths' => [],
        ];
        $definitions = app(TicketWorkflowDefinitionService::class);
        $definitions->saveDraft($workflow, $definition);
        $versionOne = $definitions->publish($workflow, $this->tech);
        [$ticket] = $this->ticketWithClient($workflow);

        // A later draft must not change the behavior of this already-pinned Ticket.
        $definition['transitions'][0]['trigger_actions'] = [];
        $definitions->saveDraft($workflow, $definition);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.messages.store', $ticket), [
                'type' => 'internal_solution',
                'visibility' => 'internal',
                'body' => 'The verified fix is documented internally.',
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame($versionOne->id, $ticket->workflow_version_id);
        $this->assertSame('work', $ticket->workflow_state_key);
        $this->assertSame($inProgress->id, $ticket->status_id);
        $this->assertDatabaseHas('ticket_workflow_histories', [
            'ticket_id' => $ticket->id,
            'workflow_version_id' => $versionOne->id,
            'transition_key' => 'start-work',
            'from_state_key' => 'intake',
            'to_state_key' => 'work',
        ]);
    }

    #[Test]
    public function satisfied_requirements_do_not_advance_without_a_configured_action_trigger(): void
    {
        $new = \App\Modules\Ticket\Models\TicketStatus::query()->where('slug', 'new')->firstOrFail();
        $inProgress = \App\Modules\Ticket\Models\TicketStatus::query()->where('slug', 'in-progress')->firstOrFail();
        $workflow = TicketWorkflow::query()->create([
            'name' => 'Deliberate manual solution transition',
            'slug' => 'deliberate-manual-solution-transition',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 77,
        ]);
        $definitions = app(TicketWorkflowDefinitionService::class);
        $definitions->saveDraft($workflow, [
            'states' => [
                $this->state('intake', $new->id, 'Intake', true),
                $this->state('work', $inProgress->id, 'Work'),
            ],
            'transitions' => [[
                'transition_key' => 'manual-after-solution',
                'from_state_key' => 'intake',
                'to_state_key' => 'work',
                'label' => 'Continue after solution',
                'manual_enabled' => true,
                'trigger_actions' => [],
                'requirements' => $this->tree('ticket.solution'),
                'sort_order' => 10,
            ]],
            'escalation_paths' => [],
        ]);
        $definitions->publish($workflow, $this->tech);
        [$ticket] = $this->ticketWithClient($workflow);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.messages.store', $ticket), [
                'type' => 'internal_solution',
                'visibility' => 'internal',
                'body' => 'Solution is ready, but this workflow requires a manual step.',
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $this->assertSame('intake', $ticket->refresh()->workflow_state_key);
        $this->assertTrue((bool) data_get($ticket->messages()->latest('id')->firstOrFail()->metadata, 'is_solution'));
        $transition = app(\App\Modules\Ticket\Services\TicketWorkflowRuntime::class)
            ->availableTransitionDecisions($ticket)[0];
        $this->assertTrue($transition['allowed']);
        $this->assertEmpty($transition['requirements_result']['missing']);
        $this->assertDatabaseMissing('ticket_workflow_histories', [
            'ticket_id' => $ticket->id,
            'transition_key' => 'manual-after-solution',
        ]);
    }

    #[Test]
    public function ticket_status_api_uses_a_workflow_transition_and_records_history(): void
    {
        $new = \App\Modules\Ticket\Models\TicketStatus::query()->where('slug', 'new')->firstOrFail();
        $inProgress = \App\Modules\Ticket\Models\TicketStatus::query()->where('slug', 'in-progress')->firstOrFail();
        $workflow = TicketWorkflow::query()->create([
            'name' => 'API status flow',
            'slug' => 'api-status-flow',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 81,
        ]);
        $definitions = app(TicketWorkflowDefinitionService::class);
        $definitions->saveDraft($workflow, [
            'states' => [
                $this->state('intake', $new->id, 'Intake', true),
                $this->state('work', $inProgress->id, 'Work'),
            ],
            'transitions' => [[
                'transition_key' => 'start-work',
                'from_state_key' => 'intake',
                'to_state_key' => 'work',
                'label' => 'Start work',
                'manual_enabled' => true,
                'trigger_actions' => [],
                'requirements' => ['match' => 'all', 'groups' => []],
                'sort_order' => 10,
            ]],
            'escalation_paths' => [],
        ]);
        $definitions->publish($workflow, $this->tech);
        [$ticket] = $this->ticketWithClient($workflow);

        Sanctum::actingAs($this->tech, ['tickets.update']);
        $this->patchJson(route('api.v1.tickets.update', $ticket), ['status_id' => $inProgress->id])
            ->assertOk()
            ->assertJsonPath('data.workflow_state_key', 'work');

        $this->assertDatabaseHas('ticket_workflow_histories', [
            'ticket_id' => $ticket->id,
            'transition_key' => 'start-work',
            'to_state_key' => 'work',
        ]);
    }

    #[Test]
    public function completed_close_moves_to_the_pinned_terminal_step_and_records_history(): void
    {
        $new = \App\Modules\Ticket\Models\TicketStatus::query()->where('slug', 'new')->firstOrFail();
        $closed = \App\Modules\Ticket\Models\TicketStatus::query()->where('slug', 'closed')->firstOrFail();
        $workflow = TicketWorkflow::query()->create([
            'name' => 'Terminal close flow',
            'slug' => 'terminal-close-flow',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 82,
        ]);
        $terminal = $this->state('closed', $closed->id, 'Closed');
        $terminal['is_terminal'] = true;
        $definitions = app(TicketWorkflowDefinitionService::class);
        $definitions->saveDraft($workflow, [
            'states' => [
                $this->state('intake', $new->id, 'Intake', true),
                $terminal,
            ],
            'transitions' => [[
                'transition_key' => 'finish',
                'from_state_key' => 'intake',
                'to_state_key' => 'closed',
                'label' => 'Finish',
                'manual_enabled' => true,
                'trigger_actions' => [],
                'requirements' => ['match' => 'all', 'groups' => []],
                'sort_order' => 10,
            ]],
            'escalation_paths' => [],
        ]);
        $version = $definitions->publish($workflow, $this->tech);
        [$ticket] = $this->ticketWithClient($workflow);

        Sanctum::actingAs($this->tech, ['tickets.actions']);
        $this->postJson(route('api.v1.tickets.close', $ticket), ['outcome' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.workflow_state_key', 'closed')
            ->assertJsonPath('data.status_id', $closed->id);

        $ticket->refresh();
        $this->assertSame($version->id, $ticket->workflow_version_id);
        $this->assertSame('closed', $ticket->workflow_state_key);
        $this->assertSame('completed', $ticket->close_outcome);
        $this->assertNotNull($ticket->closed_at);
        $this->assertDatabaseHas('ticket_workflow_histories', [
            'ticket_id' => $ticket->id,
            'workflow_version_id' => $version->id,
            'transition_key' => 'finish',
            'from_state_key' => 'intake',
            'to_state_key' => 'closed',
        ]);
    }

    #[Test]
    public function requirement_groups_support_or_inside_group_and_and_between_groups(): void
    {
        [$ticket, $client, $site] = $this->ticketWithClient();
        $asset = Asset::create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'name' => 'Mesh router',
            'type' => 'network',
            'ip_type' => 'dhcp',
            'status' => 'online',
        ]);
        $ticket->forceFill(['asset_id' => $asset->id])->save();

        $tree = [
            'match' => 'all',
            'groups' => [
                [
                    'label' => 'Customer approval',
                    'match' => 'any',
                    'conditions' => [
                        ['fact' => 'customer.response', 'operator' => 'is_true'],
                        ['fact' => 'customer.signature', 'operator' => 'is_true'],
                        ['fact' => 'commercial.valid_contract', 'operator' => 'is_true'],
                    ],
                ],
                [
                    'label' => 'Equipment',
                    'match' => 'all',
                    'conditions' => [
                        ['fact' => 'asset.linked', 'operator' => 'is_true'],
                    ],
                ],
            ],
        ];

        $evaluator = app(TicketWorkflowRequirementEvaluator::class);
        $this->assertFalse($evaluator->evaluate($ticket->refresh(), $tree)['passed']);

        $responseEvidence = TicketWorkflowEvidence::query()->create([
            'ticket_id' => $ticket->id,
            'evidence_type' => 'customer_response',
            'source_type' => 'test-message',
            'source_id' => 101,
            'fingerprint' => hash('sha256', 'customer-response'),
            'evidenced_at' => now(),
            'created_by' => $this->tech->id,
        ]);

        $this->assertTrue($evaluator->evaluate($ticket->refresh(), $tree)['passed']);

        $responseEvidence->forceFill(['invalidated_at' => now()])->save();
        TicketWorkflowEvidence::query()->create([
            'ticket_id' => $ticket->id,
            'evidence_type' => 'signature',
            'source_type' => 'test-attachment',
            'source_id' => 202,
            'fingerprint' => hash('sha256', 'signature'),
            'evidenced_at' => now(),
            'created_by' => $this->tech->id,
        ]);

        $this->assertTrue($evaluator->evaluate($ticket->refresh(), $tree)['passed']);

        $ticket->forceFill(['asset_id' => null])->save();
        $this->assertFalse($evaluator->evaluate($ticket->refresh(), $tree)['passed']);
    }

    #[Test]
    public function workflow_progress_explains_negative_gates_ignores_initial_body_and_deduplicates_requirements(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $new = $defaults['status'];
        $inProgress = \App\Modules\Ticket\Models\TicketStatus::query()->where('slug', 'in-progress')->firstOrFail();
        $workflow = TicketWorkflow::query()->create([
            'name' => 'Clear requirement progress',
            'slug' => 'clear-requirement-progress',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 79,
        ]);
        $ownerMustBeFalse = [
            'match' => 'all',
            'groups' => [[
                'match' => 'all',
                'conditions' => [[
                    'fact' => 'assignment.owner_assigned',
                    'operator' => 'is_false',
                ]],
            ]],
        ];
        $responseRequired = $this->tree('ticket.technician_response');
        $intake = $this->state('intake', $new->id, 'Intake', true);
        $intake['requirements'] = $ownerMustBeFalse;
        $work = $this->state('work', $inProgress->id, 'Work');
        $work['requirements'] = $responseRequired;
        $definitions = app(TicketWorkflowDefinitionService::class);
        $definitions->saveDraft($workflow, [
            'states' => [$intake, $work],
            'transitions' => [[
                'transition_key' => 'start-work',
                'from_state_key' => 'intake',
                'to_state_key' => 'work',
                'label' => 'Start work',
                'manual_enabled' => true,
                'trigger_actions' => [],
                'requirements' => $responseRequired,
                'sort_order' => 10,
            ]],
            'escalation_paths' => [],
        ]);
        $definitions->publish($workflow, $this->tech);

        $client = Client::factory()->create(['name' => 'Requirement Customer']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Requirement Site']);
        $ticket = app(StoreTicket::class)->handle([
            'subject' => 'New owned Ticket',
            'description' => 'The initial Ticket body is context, not technician activity.',
            'client_id' => $client->id,
            'site_id' => $site->id,
            'workflow_id' => $workflow->id,
        ], $this->tech);

        $initialMessage = $ticket->messages()->sole();
        $this->assertSame('internal_note', $initialMessage->type);
        $this->assertTrue((bool) data_get($initialMessage->metadata, 'is_default_initial_note'));
        $this->assertSame($this->tech->id, $ticket->owner_id);

        $evaluator = app(TicketWorkflowRequirementEvaluator::class);
        $ownerRequired = $evaluator->evaluate($ticket, $this->tree('assignment.owner_assigned'));
        $ownerForbidden = $evaluator->evaluate($ticket, $ownerMustBeFalse);
        $technicianResponse = $evaluator->evaluate($ticket, $responseRequired);
        $internalNote = $evaluator->evaluate($ticket, $this->tree('ticket.internal_note'));

        $replyOrInternalNote = [
            'match' => 'all',
            'groups' => [[
                'match' => 'any',
                'conditions' => [
                    ['fact' => 'ticket.technician_response', 'operator' => 'is_true'],
                    ['fact' => 'ticket.internal_note', 'operator' => 'is_true'],
                ],
            ]],
        ];

        $this->assertTrue($ownerRequired['passed']);
        $this->assertFalse($ownerForbidden['passed']);
        $this->assertSame('Must not: Ticket has an owner', data_get($ownerForbidden, 'groups.0.conditions.0.summary'));
        $this->assertSame('Ticket has an owner must not be true.', data_get($ownerForbidden, 'missing.0.reason'));
        $this->assertFalse($technicianResponse['passed']);
        $this->assertFalse($internalNote['passed']);
        $this->assertFalse($evaluator->evaluate($ticket, $replyOrInternalNote)['passed']);

        $progress = app(\App\Modules\Ticket\Services\TicketWorkflowRuntime::class)->stateProgress($ticket);
        $intakeProgress = collect($progress)->firstWhere('state_key', 'intake');
        $workProgress = collect($progress)->firstWhere('state_key', 'work');

        $this->assertSame('Must not: Ticket has an owner', data_get($intakeProgress, 'requirements.0.label'));
        $this->assertFalse(data_get($intakeProgress, 'requirements.0.passed'));
        $this->assertCount(1, $workProgress['requirements']);
        $this->assertSame(
            'Technician reply exists',
            data_get($workProgress, 'requirements.0.label'),
        );
        $this->assertFalse(data_get($workProgress, 'requirements.0.passed'));

        $followUp = TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'internal_note',
            'visibility' => 'internal',
            'body' => 'A real follow-up note.',
        ]);

        $this->assertTrue($evaluator->evaluate($ticket->refresh(), $this->tree('ticket.internal_note'))['passed']);
        $this->assertFalse($evaluator->evaluate($ticket, $responseRequired)['passed']);
        $this->assertTrue($evaluator->evaluate($ticket, $replyOrInternalNote)['passed']);

        TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'A real technician reply.',
        ]);

        $this->assertTrue($evaluator->evaluate($ticket->refresh(), $responseRequired)['passed']);
        $this->assertFalse($evaluator->evaluate($ticket, $this->tree('ticket.solution'))['passed']);

        app(\App\Modules\Ticket\Actions\MarkTicketMessageSolution::class)->handle(
            $ticket,
            $followUp,
            $this->tech,
        );
        $this->assertTrue($evaluator->evaluate($ticket->refresh(), $this->tree('ticket.solution'))['passed']);
    }

    #[Test]
    public function workflow_progress_presents_any_group_as_one_gate_and_accepts_one_true_condition(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $new = $defaults['status'];
        $inProgress = \App\Modules\Ticket\Models\TicketStatus::query()->where('slug', 'in-progress')->firstOrFail();
        $workflow = TicketWorkflow::query()->create([
            'name' => 'OR group progress',
            'slug' => 'or-group-progress',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 80,
        ]);
        $replyOrNote = [
            'match' => 'all',
            'groups' => [[
                'match' => 'any',
                'conditions' => [
                    ['fact' => 'ticket.internal_note', 'operator' => 'is_true'],
                    ['fact' => 'ticket.technician_response', 'operator' => 'is_true'],
                ],
            ]],
        ];
        $intake = $this->state('intake', $new->id, 'Intake', true);
        $work = $this->state('work', $inProgress->id, 'Work');
        $work['requirements'] = $replyOrNote;
        $definitions = app(TicketWorkflowDefinitionService::class);
        $definitions->saveDraft($workflow, [
            'states' => [$intake, $work],
            'transitions' => [[
                'transition_key' => 'start-work',
                'from_state_key' => 'intake',
                'to_state_key' => 'work',
                'label' => 'Start work',
                'manual_enabled' => true,
                'trigger_actions' => [],
                'requirements' => ['match' => 'all', 'groups' => []],
                'sort_order' => 10,
            ]],
            'escalation_paths' => [],
        ]);
        $definitions->publish($workflow, $this->tech);
        [$ticket] = $this->ticketWithClient($workflow);
        $runtime = app(\App\Modules\Ticket\Services\TicketWorkflowRuntime::class);

        $workProgress = collect($runtime->stateProgress($ticket))->firstWhere('state_key', 'work');
        $this->assertFalse($workProgress['requirements_passed']);
        $this->assertCount(1, $workProgress['requirements']);
        $this->assertSame(
            'At least one: Internal note exists OR Technician reply exists',
            data_get($workProgress, 'requirements.0.label'),
        );
        $this->assertFalse(data_get($workProgress, 'requirements.0.passed'));
        $this->assertFalse($runtime->availableTransitionDecisions($ticket)[0]['allowed']);

        TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'internal_note',
            'visibility' => 'internal',
            'body' => 'One valid OR alternative.',
        ]);

        $workProgress = collect($runtime->stateProgress($ticket->refresh()))->firstWhere('state_key', 'work');
        $this->assertTrue($workProgress['requirements_passed']);
        $this->assertCount(1, $workProgress['requirements']);
        $this->assertTrue(data_get($workProgress, 'requirements.0.passed'));
        $this->assertTrue($runtime->availableTransitionDecisions($ticket)[0]['allowed']);
    }

    #[Test]
    public function api_exposes_decisions_and_enforces_the_same_transition_requirements(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $newStatus = $defaults['status'];
        $inProgress = \App\Modules\Ticket\Models\TicketStatus::query()->where('slug', 'in-progress')->firstOrFail();
        $workflow = TicketWorkflow::query()->create([
            'name' => 'Asset Gate',
            'slug' => 'asset-gate',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 20,
        ]);
        $definition = [
            'states' => [
                $this->state('intake', $newStatus->id, 'Intake', true),
                $this->state('work', $inProgress->id, 'Work'),
            ],
            'transitions' => [[
                'transition_key' => 'start-work',
                'from_state_key' => 'intake',
                'to_state_key' => 'work',
                'label' => 'Start work',
                'manual_enabled' => true,
                'trigger_actions' => [],
                'requirements' => $this->tree('asset.linked'),
                'sort_order' => 10,
            ]],
            'escalation_paths' => [],
        ];
        app(TicketWorkflowDefinitionService::class)->saveDraft($workflow, $definition);
        app(TicketWorkflowDefinitionService::class)->publish($workflow, $this->tech);

        [$ticket, $client, $site] = $this->ticketWithClient($workflow);
        Sanctum::actingAs($this->tech, ['tickets.workflow.read', 'tickets.actions']);

        $this->getJson(route('api.v1.tickets.workflow-decisions.show', $ticket))
            ->assertOk()
            ->assertJsonPath('data.transitions.0.transition_key', 'start-work')
            ->assertJsonPath('data.transitions.0.allowed', false)
            ->assertJsonPath('data.steps.0.is_current', true)
            ->assertJsonPath('data.steps.1.name', 'Work')
            ->assertJsonPath('data.steps.1.is_available', false)
            ->assertJsonPath('data.steps.1.requirements.0.passed', false)
            ->assertJsonPath('data.actions.add_actual_cost.allowed', true);

        $this->postJson(route('api.v1.tickets.workflow-transitions.store', [$ticket, 'start-work']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transition');

        $asset = Asset::create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'name' => 'Customer PC',
            'type' => 'desktop',
            'ip_type' => 'dhcp',
            'status' => 'online',
        ]);
        $ticket->forceFill(['asset_id' => $asset->id])->save();

        $this->postJson(route('api.v1.tickets.workflow-transitions.store', [$ticket, 'start-work']), [
            'idempotency_key' => 'ticket-'.$ticket->id.'-start-work',
        ])
            ->assertOk()
            ->assertJsonPath('data.workflow_state_key', 'work')
            ->assertJsonPath('data.status_id', $inProgress->id);

        $this->assertDatabaseHas('ticket_workflow_histories', [
            'ticket_id' => $ticket->id,
            'transition_key' => 'start-work',
            'idempotency_key' => 'ticket-'.$ticket->id.'-start-work',
        ]);
    }

    #[Test]
    public function workflow_definition_api_preserves_published_versions_until_explicit_publish(): void
    {
        Permission::findOrCreate('ticket.workflow_publish', 'web');
        $this->tech->givePermissionTo('ticket.workflow_publish');
        Sanctum::actingAs($this->tech, ['tickets.workflow.manage', 'tickets.workflow.publish']);

        $status = app(EnsureTicketDefaults::class)->handle()['status'];
        $payload = [
            'name' => 'Sales escalation',
            'slug' => 'sales-escalation-api',
            'is_active' => true,
            'is_default' => false,
            'definition' => [
                'states' => [
                    $this->state('sales-intake', $status->id, 'Sales intake', true),
                    $this->state('quote-review', $status->id, 'Quote review'),
                ],
                'transitions' => [
                    [
                        'transition_key' => 'prepare-quote',
                        'from_state_key' => 'sales-intake',
                        'to_state_key' => 'quote-review',
                        'label' => 'Prepare quote',
                        'manual_enabled' => true,
                        'trigger_actions' => [],
                        'requirements' => ['match' => 'all', 'groups' => []],
                        'sort_order' => 10,
                    ],
                    [
                        'transition_key' => 'revise-declined-quote',
                        'from_state_key' => 'quote-review',
                        'to_state_key' => 'sales-intake',
                        'label' => 'Revise declined quote',
                        'manual_enabled' => true,
                        'trigger_actions' => [],
                        'requirements' => ['match' => 'all', 'groups' => []],
                        'sort_order' => 20,
                    ],
                ],
                'escalation_paths' => [],
            ],
        ];

        $this->postJson(route('api.v1.ticket-workflows.store'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.workflow.definition_status', 'draft')
            ->assertJsonPath('data.draft_definition.transitions.1.from_state_key', 'quote-review')
            ->assertJsonPath('data.draft_definition.transitions.1.to_state_key', 'sales-intake');

        $workflow = TicketWorkflow::query()->where('slug', 'sales-escalation-api')->firstOrFail();
        $this->postJson(route('api.v1.ticket-workflows.publish', $workflow))
            ->assertCreated()
            ->assertJsonPath('data.version', 1);
        $versionOne = $workflow->refresh()->publishedVersion;

        $payload['definition']['states'][0]['name'] = 'Commercial intake';
        $this->putJson(route('api.v1.ticket-workflows.update', $workflow), $payload)
            ->assertOk()
            ->assertJsonPath('data.workflow.definition_status', 'draft')
            ->assertJsonPath('data.published_definition.states.0.name', 'Sales intake');

        $this->assertSame('Sales intake', $versionOne->refresh()->definition['states'][0]['name']);
    }

    #[Test]
    public function escalation_api_enforces_requirements_action_visibility_and_eligible_assignment(): void
    {
        Permission::findOrCreate('ticket.workflow_escalate', 'web');
        $this->tech->givePermissionTo('ticket.workflow_escalate');
        $status = app(EnsureTicketDefaults::class)->handle()['status'];
        $definitions = app(TicketWorkflowDefinitionService::class);

        $senior = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $senior->assignRole('Tech');

        $target = TicketWorkflow::query()->create([
            'name' => 'Sales workflow',
            'slug' => 'sales-workflow-escalation-target',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 41,
        ]);
        $definitions->saveDraft($target, [
            'states' => [$this->state('sales-intake', $status->id, 'Sales intake', true)],
            'transitions' => [],
            'escalation_paths' => [],
        ]);
        $targetVersion = $definitions->publish($target, $this->tech);

        $source = TicketWorkflow::query()->create([
            'name' => 'Default support with escalation',
            'slug' => 'support-with-sales-escalation',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 40,
        ]);
        $intake = $this->state('support-intake', $status->id, 'Support intake', true);
        $intake['action_policy'] = [
            'add_actual_cost' => [
                'mode' => 'hidden',
                'reason' => 'Escalate before adding actual cost.',
                'requirements' => ['match' => 'all', 'groups' => []],
            ],
            'assign_other' => [
                'mode' => 'conditional',
                'requirements' => $this->tree('asset.linked'),
            ],
        ];
        $definitions->saveDraft($source, [
            'states' => [$intake],
            'transitions' => [],
            'escalation_paths' => [[
                'path_key' => 'escalate-to-sales',
                'from_state_key' => 'support-intake',
                'label' => 'Escalate to sales',
                'mode' => 'required',
                'target_workflow_id' => $target->id,
                'target_state_key' => 'sales-intake',
                'requirements' => $this->tree('sales.planned_lines'),
                'protected_actions' => ['close'],
                'assignment_strategy' => 'manual',
                'eligible_user_ids' => [$senior->id],
                'required_owner_permissions' => [],
            ]],
        ]);
        $definitions->publish($source, $this->tech);

        [$ticket] = $this->ticketWithClient($source);
        Sanctum::actingAs($this->tech, ['tickets.workflow.read', 'tickets.actions']);

        $this->getJson(route('api.v1.tickets.workflow-decisions.show', $ticket))
            ->assertOk()
            ->assertJsonPath('data.escalations.0.allowed', false)
            ->assertJsonPath('data.actions.add_actual_cost.visible', false)
            ->assertJsonPath('data.actions.assign_other.allowed', false);

        try {
            app(StoreManualTicketCostEntry::class)->handle($ticket, [
                'item_name' => 'Unapproved equipment',
                'quantity' => 1,
                'unit_price_ex_vat' => 1000,
            ], $this->tech);
            $this->fail('A hidden action must also be blocked by the server-side guard.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cost_entry', $exception->errors());
        }

        $this->postJson(route('api.v1.tickets.planned-lines.store', $ticket), [
            'name' => 'Mesh router',
            'quantity' => 1,
            'unit_price_ex_vat' => 1900,
            'unit_cost_ex_vat' => 1200,
            'vat_rate' => 25,
        ])->assertCreated();

        $this->getJson(route('api.v1.tickets.workflow-decisions.show', $ticket->refresh()))
            ->assertOk()
            ->assertJsonPath('data.escalations.0.allowed', true)
            ->assertJsonPath('data.actions.close.allowed', false)
            ->assertJsonPath('data.actions.close.mode', 'required_escalation');

        $this->postJson(route('api.v1.tickets.workflow-escalations.store', [$ticket, 'escalate-to-sales']), [
            'owner_id' => $senior->id,
            'reason' => 'The planned equipment makes this a commercial case.',
        ])
            ->assertOk()
            ->assertJsonPath('data.workflow_id', $target->id)
            ->assertJsonPath('data.workflow_version_id', $targetVersion->id)
            ->assertJsonPath('data.workflow_state_key', 'sales-intake')
            ->assertJsonPath('data.owner_id', $senior->id);

        $this->assertDatabaseHas('ticket_workflow_histories', [
            'ticket_id' => $ticket->id,
            'event_type' => 'escalated',
            'transition_key' => 'escalate-to-sales',
        ]);
    }

    #[Test]
    public function senior_review_requires_separation_of_duties_and_is_invalidated_by_scope_changes(): void
    {
        Permission::findOrCreate('ticket.review_request', 'web');
        Permission::findOrCreate('ticket.review_senior', 'web');
        Permission::findOrCreate('ticket.evidence_classify', 'web');
        $this->tech->givePermissionTo(['ticket.review_request', 'ticket.evidence_classify']);
        $senior = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $senior->assignRole('Tech');
        $senior->givePermissionTo('ticket.review_senior');
        $ticket = app(StoreTicket::class)->handle(['subject' => 'Junior work checkpoint'], $this->tech);
        $customerMessage = TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'author_type' => 'contact',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'I confirm the described scope in writing.',
        ]);

        Sanctum::actingAs($this->tech, ['tickets.actions']);
        $this->postJson(route('api.v1.tickets.workflow-evidence.store', $ticket), [
            'evidence_type' => 'customer_response',
            'source_type' => 'message',
            'source_id' => $customerMessage->id,
            'scope_key' => 'commercial-review',
            'comment' => 'Classified from the customer email thread.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.evidence_type', 'customer_response')
            ->assertJsonPath('data.source_id', $customerMessage->id);

        $this->postJson(route('api.v1.tickets.workflow-reviews.store', $ticket), [
            'gate_key' => 'commercial-review',
            'assigned_reviewer_id' => $senior->id,
            'separation_of_duties' => true,
        ])->assertCreated()->assertJsonPath('data.status', 'pending');
        $review = TicketWorkflowReview::query()->where('ticket_id', $ticket->id)->firstOrFail();

        Sanctum::actingAs($senior, ['tickets.actions']);
        $this->postJson(route('api.v1.tickets.workflow-reviews.decide', [$ticket, $review]), [
            'decision' => 'approved',
            'comment' => 'Scope checked.',
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        $approved = $review->refresh();

        $this->assertSame('approved', $approved->status);
        $this->assertTrue(app(TicketWorkflowRequirementEvaluator::class)->evaluate($ticket->refresh(), [
            'match' => 'all',
            'groups' => [[
                'match' => 'all',
                'conditions' => [[
                    'fact' => 'review.approved',
                    'operator' => 'is_true',
                    'value' => 'commercial-review',
                ]],
            ]],
        ])['passed']);

        app(StoreTicketPlannedLine::class)->handle($ticket, [
            'name' => 'Mesh router',
            'quantity' => 1,
            'unit_price_ex_vat' => 1800,
            'unit_cost_ex_vat' => 1100,
            'vat_rate' => 25,
        ], $this->tech);

        $this->assertSame('invalidated', $approved->refresh()->status);
        $this->assertNotNull($approved->invalidated_at);
    }

    #[Test]
    public function active_tickets_can_be_explicitly_migrated_to_a_new_published_version(): void
    {
        $status = app(EnsureTicketDefaults::class)->handle()['status'];
        $workflow = TicketWorkflow::query()->create([
            'name' => 'Versioned support',
            'slug' => 'versioned-support',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 30,
        ]);
        $definitions = app(TicketWorkflowDefinitionService::class);
        $versionOneDefinition = [
            'states' => [$this->state('intake', $status->id, 'Intake', true)],
            'transitions' => [],
            'escalation_paths' => [],
        ];
        $definitions->saveDraft($workflow, $versionOneDefinition);
        $versionOne = $definitions->publish($workflow, $this->tech);
        $ticket = app(StoreTicket::class)->handle([
            'subject' => 'Pinned to workflow version one',
            'workflow_id' => $workflow->id,
        ], $this->tech);

        $versionTwoDefinition = $versionOneDefinition;
        $versionTwoDefinition['states'][0]['name'] = 'Validated intake';
        $definitions->saveDraft($workflow, $versionTwoDefinition);
        $versionTwo = $definitions->publish($workflow, $this->tech);

        $this->assertSame($versionOne->id, $ticket->workflow_version_id);
        Permission::findOrCreate('ticket.workflow_migrate', 'web');
        $this->tech->givePermissionTo('ticket.workflow_migrate');
        Sanctum::actingAs($this->tech, ['tickets.workflow.manage', 'tickets.workflow.publish']);
        $this->postJson(route('api.v1.ticket-workflows.migration-preview', $workflow->refresh()), [
            'target_version_id' => $versionTwo->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.tickets.0.ticket_id', $ticket->id)
            ->assertJsonPath('data.tickets.0.target_state_key', 'intake')
            ->assertJsonPath('data.tickets.0.placement_strategy', 'stable_state_key')
            ->assertJsonPath('data.placement_mode', 'automatic');

        $this->postJson(route('api.v1.ticket-workflows.migrations.store', $workflow), [
            'target_version_id' => $versionTwo->id,
            'ticket_ids' => [$ticket->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.migrated_count', 1)
            ->assertJsonPath('data.target_version_id', $versionTwo->id)
            ->assertJsonPath('data.placement_mode', 'automatic');

        $this->assertSame($versionTwo->id, $ticket->refresh()->workflow_version_id);
        $this->assertDatabaseHas('ticket_workflow_histories', [
            'ticket_id' => $ticket->id,
            'event_type' => 'version_migrated',
            'workflow_version_id' => $versionTwo->id,
        ]);
        $history = TicketWorkflowHistory::query()
            ->where('ticket_id', $ticket->id)
            ->where('event_type', 'version_migrated')
            ->firstOrFail();
        $this->assertSame(
            'stable_state_key',
            data_get($history->metadata, 'placement_strategy'),
        );
    }

    #[Test]
    public function migration_places_tickets_from_the_same_old_step_independently_from_target_requirements(): void
    {
        $new = app(EnsureTicketDefaults::class)->handle()['status'];
        $inProgress = \App\Modules\Ticket\Models\TicketStatus::query()->where('slug', 'in-progress')->firstOrFail();
        $workflow = TicketWorkflow::query()->create([
            'name' => 'Requirement classified migration',
            'slug' => 'requirement-classified-migration',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 31,
        ]);
        $definitions = app(TicketWorkflowDefinitionService::class);
        $definitions->saveDraft($workflow, [
            'states' => [$this->state('legacy-intake', $new->id, 'Legacy intake', true)],
            'transitions' => [],
            'escalation_paths' => [],
        ]);
        $versionOne = $definitions->publish($workflow, $this->tech);
        $quietTicket = app(StoreTicket::class)->handle([
            'subject' => 'Remain in intake',
            'workflow_id' => $workflow->id,
        ], $this->tech);
        $workedTicket = app(StoreTicket::class)->handle([
            'subject' => 'Already being worked',
            'workflow_id' => $workflow->id,
        ], $this->tech);
        TicketMessage::query()->create([
            'ticket_id' => $workedTicket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'internal_note',
            'visibility' => 'internal',
            'body' => 'The technician has started work.',
        ]);

        $intake = $this->state('intake', $new->id, 'Intake', true);
        $work = $this->state('work', $inProgress->id, 'Work in progress');
        $work['requirements'] = $this->tree('ticket.internal_note');
        $definitions->saveDraft($workflow, [
            'states' => [$intake, $work],
            'transitions' => [],
            'escalation_paths' => [],
        ]);
        $versionTwo = $definitions->publish($workflow, $this->tech);

        Permission::findOrCreate('ticket.workflow_migrate', 'web');
        $this->tech->givePermissionTo('ticket.workflow_migrate');
        Sanctum::actingAs($this->tech, ['tickets.workflow.manage', 'tickets.workflow.publish']);
        $preview = $this->postJson(route('api.v1.ticket-workflows.migration-preview', $workflow), [
            'target_version_id' => $versionTwo->id,
        ])->assertOk();

        $preview->assertJsonFragment([
            'ticket_id' => $quietTicket->id,
            'target_state_key' => 'intake',
            'placement_strategy' => 'reporting_status',
        ]);
        $preview->assertJsonFragment([
            'ticket_id' => $workedTicket->id,
            'target_state_key' => 'work',
            'placement_strategy' => 'requirements',
        ]);

        $this->tech->assignRole('Admin');
        $this->actingAs($this->tech)
            ->get(route('tech.admin.settings.tickets.workflows.edit', $workflow))
            ->assertOk()
            ->assertSee('Target steps are automatically determined')
            ->assertSee('Automatically proposed')
            ->assertDontSee('Choose target step');

        Sanctum::actingAs($this->tech, ['tickets.workflow.manage', 'tickets.workflow.publish']);
        $this->postJson(route('api.v1.ticket-workflows.migrations.store', $workflow), [
            'target_version_id' => $versionTwo->id,
            'ticket_ids' => [$quietTicket->id, $workedTicket->id],
            // Legacy clients may still send this, but the server must not trust it.
            'state_mapping' => ['legacy-intake' => 'intake'],
        ])
            ->assertOk()
            ->assertJsonPath('data.migrated_count', 2)
            ->assertJsonPath('data.placement_mode', 'automatic');

        $this->assertSame($versionOne->id, $quietTicket->getOriginal('workflow_version_id'));
        $this->assertSame('intake', $quietTicket->refresh()->workflow_state_key);
        $this->assertSame($new->id, $quietTicket->status_id);
        $this->assertSame('work', $workedTicket->refresh()->workflow_state_key);
        $this->assertSame($inProgress->id, $workedTicket->status_id);
        $history = TicketWorkflowHistory::query()
            ->where('ticket_id', $workedTicket->id)
            ->where('event_type', 'version_migrated')
            ->firstOrFail();
        $this->assertSame('requirements', data_get($history->metadata, 'placement_strategy'));
        $this->assertTrue((bool) data_get($history->requirements_snapshot, 'passed'));
    }

    #[Test]
    public function migration_blocks_a_ticket_when_no_target_step_requirements_pass(): void
    {
        $new = app(EnsureTicketDefaults::class)->handle()['status'];
        $workflow = TicketWorkflow::query()->create([
            'name' => 'Blocked automatic migration',
            'slug' => 'blocked-automatic-migration',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 32,
        ]);
        $definitions = app(TicketWorkflowDefinitionService::class);
        $definitions->saveDraft($workflow, [
            'states' => [$this->state('legacy-intake', $new->id, 'Legacy intake', true)],
            'transitions' => [],
            'escalation_paths' => [],
        ]);
        $versionOne = $definitions->publish($workflow, $this->tech);
        $ticket = app(StoreTicket::class)->handle([
            'subject' => 'Cannot be classified yet',
            'workflow_id' => $workflow->id,
        ], $this->tech);

        $required = $this->state('qualified-intake', $new->id, 'Qualified intake', true);
        $required['requirements'] = $this->tree('ticket.internal_note');
        $definitions->saveDraft($workflow, [
            'states' => [$required],
            'transitions' => [],
            'escalation_paths' => [],
        ]);
        $versionTwo = $definitions->publish($workflow, $this->tech);

        Permission::findOrCreate('ticket.workflow_migrate', 'web');
        $this->tech->givePermissionTo('ticket.workflow_migrate');
        Sanctum::actingAs($this->tech, ['tickets.workflow.manage', 'tickets.workflow.publish']);
        $this->postJson(route('api.v1.ticket-workflows.migration-preview', $workflow), [
            'target_version_id' => $versionTwo->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.blocked_count', 1)
            ->assertJsonPath('data.tickets.0.ticket_id', $ticket->id)
            ->assertJsonPath('data.tickets.0.target_state_key', null);

        $this->postJson(route('api.v1.ticket-workflows.migrations.store', $workflow), [
            'target_version_id' => $versionTwo->id,
            'ticket_ids' => [$ticket->id],
            'state_mapping' => ['legacy-intake' => 'qualified-intake'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ticket_ids');

        $this->assertSame($versionOne->id, $ticket->refresh()->workflow_version_id);
        $this->assertSame('legacy-intake', $ticket->workflow_state_key);
    }

    #[Test]
    public function api_can_remove_planned_scope_and_close_with_an_explicit_no_sale_outcome(): void
    {
        [$ticket] = $this->ticketWithClient();
        Sanctum::actingAs($this->tech, ['tickets.actions']);

        $response = $this->postJson(route('api.v1.tickets.planned-lines.store', $ticket), [
            'name' => 'Optional replacement router',
            'quantity' => 1,
            'unit_price_ex_vat' => 1500,
            'vat_rate' => 25,
        ])->assertCreated();
        $lineId = (int) $response->json('data.id');

        $this->deleteJson(route('api.v1.tickets.planned-lines.destroy', [$ticket, $lineId]))
            ->assertNoContent();
        $this->assertDatabaseMissing('ticket_planned_lines', ['id' => $lineId]);

        $this->postJson(route('api.v1.tickets.close', $ticket), [
            'outcome' => 'no_sale',
            'reason' => 'The customer decided not to proceed with the optional purchase.',
        ])
            ->assertOk()
            ->assertJsonPath('data.close_outcome', 'no_sale');

        $this->assertNotNull($ticket->refresh()->closed_at);
        $this->assertSame('no_sale', $ticket->close_outcome);
    }

    #[Test]
    public function ticket_quote_api_covers_customer_acceptance_conversion_and_purchase_need(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$ticket, $client, $site] = $this->ticketWithClient();
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Kari Kunde',
            'email' => 'kari@example.test',
            'active' => true,
        ]);
        $ticket->forceFill([
            'contact_id' => $contact->id,
            'portal_visible_at' => now(),
            'portal_visible_by' => $this->tech->id,
        ])->save();

        $warehouse = StorageWarehouse::query()->create([
            'name' => 'Workflow Warehouse',
            'code' => 'WFLOW',
            'is_active' => true,
        ]);
        $vendor = Vendor::query()->create([
            'name' => 'Router Supplier',
            'vendor_code' => 'ROUTER-SUP',
            'is_vendor' => true,
            'is_supplier' => true,
            'is_active' => true,
        ]);
        $router = StorageItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'primary_vendor_id' => $vendor->id,
            'sku' => 'MESH-01',
            'name' => 'Mesh router',
            'short_description' => 'Mesh router including configuration.',
            'purchase_price' => 1200,
            'sale_price' => 1900,
            'vat_rate' => 25,
            'qty_on_hand' => 0,
            'qty_reserved' => 0,
            'can_be_ordered' => true,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->tech, ['tickets.actions']);
        $this->postJson(route('api.v1.tickets.planned-lines.store', $ticket), [
            'storage_item_id' => $router->id,
            'quantity' => 1,
        ])->assertCreated();
        $this->postJson(route('api.v1.tickets.planned-lines.store', $ticket), [
            'name' => 'Installation and configuration',
            'description' => 'Install and configure the new mesh network.',
            'quantity' => 2,
            'unit' => 'hours',
            'unit_cost_ex_vat' => 650,
            'unit_price_ex_vat' => 1100,
            'vat_rate' => 25,
            'downstream_type' => 'implementation',
        ])->assertCreated();

        $this->postJson(route('api.v1.tickets.sales-quote.store', $ticket))
            ->assertCreated()
            ->assertJsonCount(2, 'data.lines');
        $versionId = $ticket->refresh()->salesContext()->firstOrFail()->opportunity->current_quote_version_id;

        $this->postJson(route('api.v1.tickets.sales-quote.send', $ticket), [
            'subject' => 'Quote for mesh upgrade',
            'body' => 'Here is the complete cost for equipment and setup.',
            'reply_contact_id' => $contact->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $sentVersion = \App\Modules\Sales\Models\SalesQuoteVersion::query()->findOrFail($versionId);
        Storage::disk('local')->assertExists($sentVersion->pdf_snapshot_path);
        $this->assertNotNull($sentVersion->pdf_snapshot_sha256);
        $this->assertDatabaseHas('ticket_attachments', [
            'ticket_id' => $ticket->id,
            'source' => 'sales_quote_snapshot',
        ]);

        $customerMessage = TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'author_id' => null,
            'author_type' => 'contact',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'subject' => 'Re: Quote for mesh upgrade',
            'body' => 'I accept the complete quote. Please proceed.',
        ]);

        $this->postJson(route('api.v1.tickets.sales-quote.accept-message', [$ticket, $customerMessage, $sentVersion]), [
            'name' => 'Kari Kunde',
            'comment' => 'Acceptance received in the Ticket email thread.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertSame('won', $ticket->refresh()->salesContext->opportunity->refresh()->status);
        $this->assertSame(2, $ticket->plannedLines()->where('status', 'approved')->count());

        $routerLine = TicketPlannedLine::query()->where('ticket_id', $ticket->id)->where('storage_item_id', $router->id)->firstOrFail();
        $this->postJson(route('api.v1.tickets.planned-lines.purchase', [$ticket, $routerLine]))
            ->assertCreated()
            ->assertJsonPath('data.metadata.vendor_order_sent', false);
        $this->assertDatabaseHas('storage_purchase_orders', [
            'status' => 'draft',
            'vendor_id' => $vendor->id,
        ]);

        $implementationLine = TicketPlannedLine::query()->where('ticket_id', $ticket->id)->whereNull('storage_item_id')->firstOrFail();
        $this->postJson(route('api.v1.tickets.planned-lines.convert', [$ticket, $implementationLine]))
            ->assertOk()
            ->assertJsonPath('data.item_name', 'Installation and configuration');
        $this->assertDatabaseHas('ticket_cost_entries', [
            'ticket_id' => $ticket->id,
            'item_name' => 'Installation and configuration',
            'billing_status' => 'pending',
        ]);
    }

    /** @return array{0: TicketWorkflow, 1: \App\Modules\Ticket\Models\TicketWorkflowVersion, 2: \App\Modules\Ticket\Models\TicketStatus} */
    private function activityWorkflow(string $slug, ?string $requiredFact = null): array
    {
        $new = \App\Modules\Ticket\Models\TicketStatus::query()->where('slug', 'new')->firstOrFail();
        $inProgress = \App\Modules\Ticket\Models\TicketStatus::query()->where('slug', 'in-progress')->firstOrFail();
        $workflow = TicketWorkflow::query()->create([
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 78,
        ]);
        $definitions = app(TicketWorkflowDefinitionService::class);
        $definitions->saveDraft($workflow, [
            'states' => [
                $this->state('intake', $new->id, 'Intake', true),
                $this->state('work', $inProgress->id, 'Work'),
            ],
            'transitions' => [[
                'transition_key' => 'first-activity',
                'from_state_key' => 'intake',
                'to_state_key' => 'work',
                'label' => 'First activity',
                'manual_enabled' => false,
                'trigger_actions' => [TicketAction::ANY_TECHNICIAN_ACTIVITY],
                'requirements' => $requiredFact ? $this->tree($requiredFact) : ['match' => 'all', 'groups' => []],
                'sort_order' => 10,
            ]],
            'escalation_paths' => [],
        ]);

        return [$workflow, $definitions->publish($workflow, $this->tech), $inProgress];
    }

    /** @return array{0: Ticket, 1: Client, 2: ClientSite} */
    private function ticketWithClient(?TicketWorkflow $workflow = null): array
    {
        $client = Client::factory()->create(['name' => 'Workflow Customer']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Main site']);
        $ticket = app(StoreTicket::class)->handle([
            'subject' => 'Workflow test ticket',
            'client_id' => $client->id,
            'site_id' => $site->id,
            'workflow_id' => $workflow?->id,
        ], $this->tech);

        return [$ticket, $client, $site];
    }

    /** @return array<string, mixed> */
    private function state(string $key, int $statusId, string $name, bool $initial = false): array
    {
        return [
            'state_key' => $key,
            'ticket_status_id' => $statusId,
            'name' => $name,
            'is_initial' => $initial,
            'is_terminal' => false,
            'requirements' => ['match' => 'all', 'groups' => []],
            'action_policy' => [],
            'assignment_policy' => [],
            'commercial_policy' => [],
            'sort_order' => $initial ? 10 : 20,
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
                ]],
            ]],
        ];
    }
}
