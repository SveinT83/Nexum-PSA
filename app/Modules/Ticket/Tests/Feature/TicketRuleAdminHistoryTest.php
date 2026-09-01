<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleActionResult;
use App\Modules\Ticket\Models\TicketRuleEvent;
use App\Modules\Ticket\Models\TicketRuleExecution;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Queries\TicketRuleExecutionDetailQuery;
use App\Modules\Ticket\Queries\TicketRuleExecutionIndexQuery;
use App\Modules\Ticket\Services\TicketRuleExecutionPresenter;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketRuleAdminHistoryTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $defaults;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaults = app(EnsureTicketDefaults::class)->handle();
        foreach ([
            'ticket.view',
            'ticket.manage_rules',
            'ticket.rule_execution_view',
            'ticket.rule_retry',
            'ticket.rule_full_rerun',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    #[Test]
    public function exact_route_permissions_and_ticket_view_are_both_enforced(): void
    {
        $fixture = $this->evidence($this->ticket('TD-2026-860101'), 'route-secret');
        $rulesOnly = $this->operator(['ticket.manage_rules']);

        $this->actingAs($rulesOnly)
            ->get(route('tech.admin.settings.tickets.rules'))
            ->assertOk()
            ->assertDontSee(
                route('tech.admin.settings.tickets.rules.executions.index'),
                false,
            );
        $rulesAndExecution = $this->operator([
            'ticket.manage_rules',
            'ticket.rule_execution_view',
        ]);
        $this->actingAs($rulesAndExecution)
            ->get(route('tech.admin.settings.tickets.rules'))
            ->assertOk()
            ->assertDontSee(
                route('tech.admin.settings.tickets.rules.executions.index'),
                false,
            );
        $ruleHistoryManager = $this->operator([
            'ticket.manage_rules',
            'ticket.view',
            'ticket.rule_execution_view',
        ]);
        $this->actingAs($ruleHistoryManager)
            ->get(route('tech.admin.settings.tickets.rules'))
            ->assertOk()
            ->assertSee(
                route('tech.admin.settings.tickets.rules.executions.index', ['rule_id' => $fixture['rule']->id]),
                false,
            );
        $this->actingAs($rulesOnly)
            ->get(route('tech.admin.settings.tickets.rules.show', $fixture['rule']))
            ->assertOk()
            ->assertSee('Legacy logs')
            ->assertSee('historical read-only records');
        $this->actingAs($rulesOnly)
            ->get(route('tech.admin.settings.tickets.rules.executions.index'))
            ->assertForbidden();

        $executionPermissionOnly = $this->operator(['ticket.rule_execution_view']);
        $this->actingAs($executionPermissionOnly)
            ->get(route('tech.admin.settings.tickets.rules.executions.index'))
            ->assertForbidden();

        $viewer = $this->operator(['ticket.view', 'ticket.rule_execution_view']);
        $this->actingAs($viewer)
            ->get(route('tech.admin.settings.tickets.rules.executions.index'))
            ->assertOk()
            ->assertSee('Execution ledger')
            ->assertSee($fixture['ticket']->ticket_key);
        $this->actingAs($viewer)
            ->get(route('tech.admin.settings.tickets.rules.executions.show', $fixture['run']))
            ->assertOk()
            ->assertSee('Ticket Rule execution #'.$fixture['run']->id)
            ->assertDontSee(
                route('tech.admin.settings.tickets.rules.show', $fixture['rule']),
                false,
            );

        $this->actingAs($viewer)
            ->post(route('tech.admin.settings.tickets.rules.executions.actions.retry', [
                'run' => $fixture['run'],
                'actionResult' => $fixture['action'],
            ]))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('tech.admin.settings.tickets.rules.executions.rerun.preview', $fixture['run']))
            ->assertForbidden();

        $customer = $this->portalOnlyUser();
        $this->actingAs($customer)
            ->get(route('tech.admin.settings.tickets.rules.show', $fixture['rule']))
            ->assertForbidden();
        $this->actingAs($customer)
            ->get(route('tech.admin.settings.tickets.rules.executions.index'))
            ->assertForbidden();
        $this->actingAs($customer)
            ->get(route('tech.admin.settings.tickets.rules.executions.show', $fixture['run']))
            ->assertForbidden();
    }

    #[Test]
    public function execution_rule_filter_options_are_bounded_and_keep_the_selected_rule_visible(): void
    {
        for ($index = 1; $index <= 204; $index++) {
            TicketRule::query()->create([
                'name' => sprintf('Bounded selector rule %03d', $index),
                'description' => 'Bounded execution selector fixture.',
                'trigger' => TicketRule::TRIGGER_CREATE,
                'weight' => $index,
                'is_active' => false,
                'stop_processing' => false,
                'conditions_json' => [],
                'actions_json' => [],
            ]);
        }
        $selected = TicketRule::query()->create([
            'name' => 'ZZZ selected execution filter rule',
            'description' => 'Selected rule outside the bounded alphabetical window.',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => 999,
            'is_active' => false,
            'stop_processing' => false,
            'conditions_json' => [],
            'actions_json' => [],
        ]);

        $selector = app(TicketRuleExecutionIndexQuery::class)->ruleOptions($selected->id);

        $this->assertCount(200, $selector['options']);
        $this->assertSame($selected->id, $selector['options']->first()->id);
        $this->assertTrue($selector['options']->contains('id', $selected->id));
        $this->assertSame(
            TicketRule::query()->withTrashed()->count() - 200,
            $selector['omitted_count'],
        );

        $viewer = $this->operator(['ticket.view', 'ticket.rule_execution_view']);
        $this->actingAs($viewer)
            ->get(route('tech.admin.settings.tickets.rules.executions.index', [
                'rule_id' => $selected->id,
            ]))
            ->assertOk()
            ->assertSee($selected->name)
            ->assertSee($selector['omitted_count'].' additional rules are omitted');
    }

    #[Test]
    public function history_is_paginated_filterable_and_re_sanitized_at_presentation(): void
    {
        $secret = 'private-history-value-abc-123';
        $initiator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $first = $this->evidence($this->ticket('TD-2026-860102'), $secret, $initiator);
        $second = $this->evidence($this->ticket('TD-2026-860103'), 'other-private-value');
        $initiatorId = $initiator->id;
        $initiator->delete();
        $first['rule']->delete();

        $query = app(TicketRuleExecutionIndexQuery::class);
        $page = $query->paginate([], 1);
        $this->assertSame(2, $page->total());
        $this->assertSame(2, $page->lastPage());

        $filtered = $query->paginate([
            'rule_id' => $first['rule']->id,
            'ticket' => $first['ticket']->ticket_key,
            'event' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'result' => TicketRuleRun::STATUS_FAILED,
            'from' => now()->format('Y-m-d'),
            'to' => now()->format('Y-m-d'),
        ]);
        $this->assertSame(1, $filtered->total());
        $this->assertSame($first['run']->id, $filtered->first()->id);

        $viewer = $this->operator([
            'ticket.view',
            'ticket.rule_execution_view',
            'ticket.manage_rules',
        ]);
        $response = $this->actingAs($viewer)
            ->get(route('tech.admin.settings.tickets.rules.executions.show', $first['run']));

        $response->assertOk()
            ->assertSee('Deleted user #'.$initiatorId)
            ->assertSee($first['rule']->name)
            ->assertSee('Failure fingerprint:')
            ->assertSee(hash('sha256', $secret))
            ->assertDontSee($secret)
            ->assertSee(route('tech.admin.settings.tickets.rules.show', $first['rule']), false);

        $this->actingAs($viewer)
            ->get(route('tech.admin.settings.tickets.rules.show', $first['rule']->id))
            ->assertOk()
            ->assertSee('Deleted')
            ->assertSee($first['rule']->name);

        $this->assertNotSame($first['run']->id, $second['run']->id);
    }

    #[Test]
    public function custom_field_evidence_honors_definition_visibility_and_never_exposes_raw_values(): void
    {
        $permission = 'ticket.custom_field_private_history';
        Permission::findOrCreate($permission, 'web');
        $secret = 'raw-custom-field-history-secret';
        $definition = CustomFieldDefinition::query()->create([
            'model_type' => Ticket::class,
            'key' => 'private_history_field',
            'label' => 'Private history field',
            'field_type' => CustomFieldDefinition::TYPE_TEXT,
            'visible_in_ui' => true,
            'editable_in_ui' => true,
            'editable_via_api' => false,
            'searchable' => false,
            'unique_per_model' => false,
            'required' => false,
            'admin_only' => false,
            'view_permission' => $permission,
            'active' => true,
        ]);
        $field = 'custom_field.'.$definition->id;
        $projection = [
            'definition_id' => $definition->id,
            'field_type' => CustomFieldDefinition::TYPE_TEXT,
            'present' => true,
            'value' => $secret,
        ];
        $fixture = $this->evidence(
            $this->ticket('TD-2026-860106'),
            'safe-fixture',
            overrides: [
                'event' => [
                    'changed_fields_json' => [$field],
                    'before_json' => [$field => $projection],
                    'after_json' => [$field => $projection],
                ],
                'execution' => [
                    'condition_evidence_json' => [
                        'valid' => true,
                        'passed' => true,
                        'mode' => 'grouped',
                        'root_match' => 'ALL',
                        'groups' => [[
                            'position' => 0,
                            'match' => 'ALL',
                            'passed' => true,
                            'rows' => [[
                                'position' => 0,
                                'field' => 'custom_field.current',
                                'target' => ['definition_id' => $definition->id],
                                'operator' => 'equals',
                                'passed' => true,
                                'expected' => $secret,
                                'actual' => $secret,
                            ]],
                        ]],
                    ],
                    'change_summary_json' => [$field => $projection],
                ],
                'action' => [
                    'action_type' => 'set_custom_field',
                    'action_snapshot_json' => [
                        'type' => 'set_custom_field',
                        'input' => [
                            'target' => ['definition_id' => $definition->id],
                            'value' => $secret,
                        ],
                    ],
                    'change_json' => [$field => $projection],
                ],
            ],
        );

        $restricted = $this->operator(['ticket.view', 'ticket.rule_execution_view']);
        $this->actingAs($restricted)
            ->get(route('tech.admin.settings.tickets.rules.executions.show', $fixture['run']))
            ->assertOk()
            ->assertDontSee('Custom Field #'.$definition->id)
            ->assertDontSee($secret);

        $allowed = $this->operator(['ticket.view', 'ticket.rule_execution_view', $permission]);
        $this->actingAs($allowed)
            ->get(route('tech.admin.settings.tickets.rules.executions.show', $fixture['run']))
            ->assertOk()
            ->assertSee('Custom Field #'.$definition->id)
            ->assertDontSee($secret);
    }

    #[Test]
    public function private_custom_field_rules_collapse_opposite_branches_and_outcomes_to_one_restricted_projection(): void
    {
        $permission = 'ticket.custom_field_private_branch_history';
        Permission::findOrCreate($permission, 'web');
        $customField = CustomFieldDefinition::query()->create([
            'model_type' => Ticket::class,
            'key' => 'private_branch_history',
            'label' => 'Private branch history',
            'field_type' => CustomFieldDefinition::TYPE_TEXT,
            'visible_in_ui' => true,
            'editable_in_ui' => true,
            'editable_via_api' => false,
            'searchable' => false,
            'unique_per_model' => false,
            'required' => false,
            'admin_only' => false,
            'view_permission' => $permission,
            'edit_permission' => $permission,
            'active' => true,
        ]);
        $target = ['definition_id' => $customField->id];
        $immutableDefinition = [
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'trigger_filters' => [],
            'conditions' => [
                'mode' => 'grouped',
                'match' => 'ALL',
                'groups' => [[
                    'match' => 'ALL',
                    'conditions' => [[
                        'field' => 'custom_field.current',
                        'target' => $target,
                        'operator' => 'equals',
                        'value' => 'private-opposite-value',
                    ]],
                ]],
            ],
            'then_actions' => [[
                'type' => 'set_queue',
                'input' => ['queue_id' => $this->defaults['queue']->id],
            ]],
            'else_actions' => [[
                'type' => 'set_priority',
                'input' => ['priority_id' => $this->defaults['priority']->id],
            ]],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 10],
        ];
        $then = $this->evidence(
            $this->ticket('TD-2026-860109'),
            'then-private-summary',
            overrides: [
                'definition' => $immutableDefinition,
                'run' => [
                    'status' => TicketRuleRun::STATUS_SUCCEEDED,
                    'termination_reason' => 'then_private_outcome',
                    'counters_json' => ['events' => 1, 'actions' => 1],
                    'safe_summary_json' => ['branch' => 'then-private-summary'],
                    'duration_ms' => 111,
                ],
                'execution' => [
                    'status' => TicketRuleExecution::STATUS_SUCCEEDED,
                    'conditions_matched' => true,
                    'selected_branch' => 'then',
                    'condition_evidence_json' => [
                        'valid' => true,
                        'passed' => true,
                        'mode' => 'grouped',
                        'root_match' => 'ALL',
                        'groups' => [[
                            'position' => 0,
                            'match' => 'ALL',
                            'passed' => true,
                            'rows' => [[
                                'position' => 0,
                                'field' => 'custom_field.current',
                                'target' => $target,
                                'operator' => 'equals',
                                'passed' => true,
                                'expected' => 'private-opposite-value',
                                'actual' => 'private-opposite-value',
                            ]],
                        ]],
                    ],
                    'change_summary_json' => ['queue_id' => 1],
                    'failure_code' => null,
                    'failure_message' => null,
                ],
                'action' => [
                    'branch' => 'then',
                    'action_type' => 'set_queue',
                    'status' => TicketRuleActionResult::STATUS_SUCCEEDED,
                    'change_json' => ['queue_id' => 1],
                    'failure_code' => null,
                    'failure_message' => null,
                ],
            ],
        );
        $else = $this->evidence(
            $this->ticket('TD-2026-860110'),
            'else-private-summary',
            overrides: [
                'definition' => $immutableDefinition,
                'run' => [
                    'status' => TicketRuleRun::STATUS_FAILED,
                    'termination_reason' => 'else_private_failure',
                    'counters_json' => ['events' => 9, 'actions' => 9],
                    'safe_summary_json' => ['branch' => 'else-private-summary'],
                    'duration_ms' => 999,
                ],
                'execution' => [
                    'status' => TicketRuleExecution::STATUS_FAILED,
                    'conditions_matched' => false,
                    'selected_branch' => 'else',
                    'condition_evidence_json' => [
                        'valid' => true,
                        'passed' => false,
                        'mode' => 'grouped',
                        'root_match' => 'ALL',
                        'groups' => [[
                            'position' => 0,
                            'match' => 'ALL',
                            'passed' => false,
                            'rows' => [[
                                'position' => 0,
                                'field' => 'custom_field.current',
                                'target' => $target,
                                'operator' => 'equals',
                                'passed' => false,
                                'expected' => 'private-opposite-value',
                                'actual' => 'opposite-private-value',
                            ]],
                        ]],
                    ],
                    'change_summary_json' => ['priority_id' => 9],
                    'failure_code' => 'else_private_failure',
                    'failure_message' => 'else-private-summary',
                ],
                'action' => [
                    'branch' => 'else',
                    'action_type' => 'set_priority',
                    'status' => TicketRuleActionResult::STATUS_FAILED,
                    'change_json' => ['priority_id' => 9],
                    'failure_code' => 'else_private_action_failure',
                    'failure_message' => 'else-private-summary',
                ],
            ],
        );
        $viewer = $this->operator([
            'ticket.view',
            'ticket.rule_execution_view',
            'ticket.rule_retry',
        ]);
        $load = function (TicketRuleRun $run): TicketRuleRun {
            $run = $run->fresh();
            $run->load([
                'ticket',
                'events',
                'executions.rule',
                'executions.version',
                'afterCommitResults',
            ])->loadCount(['events', 'executions', 'actionResults']);
            app(TicketRuleExecutionDetailQuery::class)->hydrateBoundedActionAttempts($run);

            return $run;
        };
        $presenter = app(TicketRuleExecutionPresenter::class);
        $thenRun = $load($then['run']);
        $elseRun = $load($else['run']);
        $thenSummary = $presenter->runSummary($thenRun, $viewer);
        $elseSummary = $presenter->runSummary($elseRun, $viewer);

        foreach ([
            'status',
            'status_label',
            'status_class',
            'restricted_evidence',
            'restricted_message',
            'rule_names',
            'rule_names_omitted',
            'event_count',
            'execution_count',
            'action_count',
            'duration_ms',
        ] as $key) {
            $this->assertSame($thenSummary[$key], $elseSummary[$key], $key);
        }
        $this->assertSame('restricted_evidence', $thenSummary['status']);
        $this->assertNull($thenSummary['event_count']);
        $this->assertNull($thenSummary['execution_count']);
        $this->assertNull($thenSummary['action_count']);
        $this->assertNull($thenSummary['duration_ms']);

        $thenDetail = $presenter->runDetail($thenRun, $viewer);
        $elseDetail = $presenter->runDetail($elseRun, $viewer);
        foreach ([
            'published_version_ids',
            'termination_reason',
            'counters',
            'safe_summary',
            'events',
            'executions',
            'after_commit_results',
        ] as $key) {
            $this->assertSame($thenDetail[$key], $elseDetail[$key], $key);
        }
        $this->assertSame([], $thenDetail['events']);
        $this->assertSame([], $thenDetail['executions']);

        $index = $this->actingAs($viewer)
            ->get(route('tech.admin.settings.tickets.rules.executions.index'))
            ->assertOk()
            ->assertSee('Restricted evidence')
            ->assertDontSee('then-private-summary')
            ->assertDontSee('else-private-summary');
        $this->assertGreaterThanOrEqual(2, substr_count($index->getContent(), 'Restricted evidence'));

        foreach ([TicketRuleRun::STATUS_SUCCEEDED, TicketRuleRun::STATUS_FAILED] as $result) {
            $this->actingAs($viewer)
                ->get(route('tech.admin.settings.tickets.rules.executions.index', [
                    'result' => $result,
                    'sort' => 'status',
                    'direction' => 'asc',
                ]))
                ->assertOk()
                ->assertSee('Result filtering and result/duration sorting are unavailable because restricted execution evidence exists.')
                ->assertSee($then['ticket']->ticket_key)
                ->assertSee($else['ticket']->ticket_key)
                ->assertDontSee('<option value="status"', false)
                ->assertDontSee('<option value="duration"', false);
        }

        foreach ([$then, $else] as $fixture) {
            $this->actingAs($viewer)
                ->get(route('tech.admin.settings.tickets.rules.executions.show', $fixture['run']))
                ->assertOk()
                ->assertSee('Restricted evidence')
                ->assertDontSee('Set Queue')
                ->assertDontSee('Set Priority')
                ->assertDontSee('then_private_outcome')
                ->assertDontSee('else_private_failure')
                ->assertDontSee('then-private-summary')
                ->assertDontSee('else-private-summary')
                ->assertDontSee('Retry position');
        }
    }

    #[Test]
    public function execution_detail_uses_a_sql_bounded_attempt_window_and_reports_historical_omissions(): void
    {
        config()->set('ticket_rules.retry.max_attempts_per_position', 3);
        $fixture = $this->evidence(
            $this->ticket('TD-2026-860111'),
            'bounded-attempt-private-value',
        );
        $source = $fixture['action'];
        $previous = $source;

        foreach (range(2, 6) as $attemptNumber) {
            $previous = TicketRuleActionResult::query()->create([
                'run_id' => $source->run_id,
                'event_id' => $source->event_id,
                'execution_id' => $source->execution_id,
                'ticket_id' => $source->ticket_id,
                'ticket_rule_id' => $source->ticket_rule_id,
                'rule_version_id' => $source->rule_version_id,
                'branch' => $source->branch,
                'position' => $source->position,
                'action_type' => $source->action_type,
                'position_key' => $source->position_key,
                'attempt_number' => $attemptNumber,
                'retry_of_id' => $previous->id,
                'precondition_fingerprint' => $source->precondition_fingerprint,
                'idempotency_key' => hash('sha256', 'bounded-attempt-'.Str::uuid()),
                'action_snapshot_json' => $source->action_snapshot_json,
                'status' => TicketRuleActionResult::STATUS_FAILED,
                'change_json' => ['attempt' => $attemptNumber],
                'authorization_json' => ['allowed' => false],
                'failure_code' => 'bounded_attempt_failure',
                'failure_message' => null,
                'started_at' => now()->subSecond(),
                'completed_at' => now(),
                'duration_ms' => $attemptNumber,
            ]);
        }

        $run = $fixture['run']->fresh();
        $run->load([
            'executions' => fn ($query) => $query->with(['rule', 'version']),
        ]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(TicketRuleExecutionDetailQuery::class)->hydrateBoundedActionAttempts($run);
        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        $execution = $run->executions->firstOrFail();
        $this->assertSame(
            [4, 5, 6],
            $execution->actionResults->pluck('attempt_number')->map('intval')->all(),
        );
        $this->assertSame(3, (int) $execution->action_attempts_omitted_count);
        $sql = strtolower(collect($queryLog)->pluck('query')->implode(' '));
        $this->assertStringContainsString('row_number() over', $sql);
        $this->assertStringContainsString('attempt_rank', $sql);

        $viewer = $this->operator(['ticket.view', 'ticket.rule_execution_view']);
        $this->actingAs($viewer)
            ->get(route('tech.admin.settings.tickets.rules.executions.show', $fixture['run']))
            ->assertOk()
            ->assertSee('3 earlier action attempts were omitted by the per-position history bound.')
            ->assertSee('attempt 4')
            ->assertSee('attempt 5')
            ->assertSee('attempt 6')
            ->assertDontSee('attempt 1')
            ->assertDontSee('attempt 2')
            ->assertDontSee('attempt 3');
    }

    #[Test]
    public function ticket_history_links_one_compact_automation_run_only_for_execution_viewers(): void
    {
        $fixture = $this->evidence(
            $this->ticket('TD-2026-860108'),
            'ticket-history-private-value',
        );
        TicketEvent::query()->create([
            'ticket_id' => $fixture['ticket']->id,
            'actor_id' => null,
            'ticket_rule_run_id' => $fixture['run']->id,
            'type' => 'automation_run',
            'message' => 'Ticket Rule automation completed.',
            'after' => [
                'terminal_status' => TicketRuleRun::STATUS_FAILED,
                'private_summary' => 'must-not-render-in-ticket-history',
            ],
        ]);
        $detailUrl = route(
            'tech.admin.settings.tickets.rules.executions.show',
            ['run' => $fixture['run']->id],
        );

        $viewer = $this->operator(['ticket.view', 'ticket.rule_execution_view']);
        $authorizedResponse = $this->actingAs($viewer)
            ->get(route('tech.tickets.show', $fixture['ticket']))
            ->assertOk()
            ->assertSee('Automation run')
            ->assertSee('View automation execution #'.$fixture['run']->id)
            ->assertSee($detailUrl, false)
            ->assertDontSee($fixture['rule']->name)
            ->assertDontSee('must-not-render-in-ticket-history');
        $this->assertSame(1, substr_count($authorizedResponse->getContent(), $detailUrl));

        $ticketViewer = $this->operator(['ticket.view']);
        $this->actingAs($ticketViewer)
            ->get(route('tech.tickets.show', $fixture['ticket']))
            ->assertOk()
            ->assertSee('Automation run')
            ->assertDontSee($detailUrl, false)
            ->assertDontSee($fixture['rule']->name)
            ->assertDontSee('must-not-render-in-ticket-history');

        $this->actingAs($this->portalOnlyUser())
            ->get(route('tech.tickets.show', $fixture['ticket']))
            ->assertForbidden()
            ->assertDontSee($detailUrl, false);
    }

    #[Test]
    public function deleted_ticket_history_keeps_only_the_safe_missing_record_fallback(): void
    {
        $fixture = $this->evidence(
            $this->ticket('TD-2026-860107'),
            'deleted-ticket-private-history',
        );
        $ticketId = (int) $fixture['ticket']->id;
        $fixture['ticket']->delete();
        $viewer = $this->operator(['ticket.view', 'ticket.rule_execution_view']);

        $this->actingAs($viewer)
            ->get(route('tech.admin.settings.tickets.rules.executions.index'))
            ->assertOk()
            ->assertSee('Deleted Ticket #'.$ticketId)
            ->assertDontSee('deleted-ticket-private-history');
        $this->actingAs($viewer)
            ->get(route('tech.admin.settings.tickets.rules.executions.show', $fixture['run']))
            ->assertOk()
            ->assertSee('Deleted Ticket #'.$ticketId)
            ->assertDontSee('deleted-ticket-private-history');
    }

    #[Test]
    public function action_retry_route_rejects_an_action_from_another_ticket_and_run(): void
    {
        $first = $this->evidence($this->ticket('TD-2026-860104'), 'first-isolation-secret');
        $second = $this->evidence($this->ticket('TD-2026-860105'), 'second-isolation-secret');
        $operator = $this->operator(['ticket.view', 'ticket.rule_retry']);
        $before = TicketRuleActionResult::query()->count();

        $this->actingAs($operator)
            ->post(route('tech.admin.settings.tickets.rules.executions.actions.retry', [
                'run' => $first['run'],
                'actionResult' => $second['action'],
            ]))
            ->assertNotFound();

        $this->assertSame($before, TicketRuleActionResult::query()->count());
        $this->assertSame($first['ticket']->id, $first['run']->ticket_id);
        $this->assertSame($second['ticket']->id, $second['run']->ticket_id);
    }

    /**
     * @return array{ticket: Ticket, rule: TicketRule, version: TicketRuleVersion, run: TicketRuleRun, action: TicketRuleActionResult}
     */
    private function evidence(
        Ticket $ticket,
        string $privateValue,
        ?User $initiator = null,
        array $overrides = [],
    ): array {
        $definition = $overrides['definition'] ?? [
            'schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'trigger' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'conditions' => ['match' => 'ALL', 'groups' => []],
            'then_actions' => [['type' => 'set_queue', 'value' => $this->defaults['queue']->id]],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 10],
        ];
        $checksum = TicketRuleStableJson::checksum($definition);
        $rule = TicketRule::query()->create([
            'name' => 'History rule '.Str::random(8),
            'description' => 'Safe rule description.',
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
            'status' => TicketRuleVersion::STATUS_COMPATIBILITY,
            'definition_schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'trigger_key' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'weight' => 10,
            'stop_processing' => false,
            'name' => $rule->name,
            'description' => 'Safe immutable version description.',
            'definition_json' => $definition,
            'definition_checksum' => $checksum,
            'source_is_active' => true,
            'source_trigger' => TicketRule::TRIGGER_CREATE,
            'source_hit_count' => 0,
            'provenance' => TicketRuleVersion::PROVENANCE_LEGACY_BACKFILL,
            'provenance_batch_uuid' => (string) Str::uuid(),
            'provenance_recorded_at' => now(),
        ]);
        DB::table('ticket_rules')->where('id', $rule->id)->update([
            'lifecycle_status' => TicketRule::LIFECYCLE_PUBLISHED,
            'published_version_id' => $version->id,
            'definition_schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'definition_checksum' => $checksum,
            'compatibility_status' => TicketRule::COMPATIBILITY_ELIGIBLE,
            'compatibility_checked_at' => now(),
        ]);

        $run = TicketRuleRun::query()->create(array_merge([
            'ticket_id' => $ticket->id,
            'root_event_key' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'source_channel' => 'test',
            'source_action' => 'TicketRuleAdminHistoryTest',
            'initiator_type' => $initiator ? 'user' : null,
            'initiator_id' => $initiator?->id,
            'automation_actor_id' => null,
            'correlation_uuid' => (string) Str::uuid(),
            'causation_uuid' => null,
            'root_idempotency_key' => hash('sha256', 'run-'.Str::uuid()),
            'mode' => 'runtime',
            'attempt_number' => 1,
            'retry_of_run_id' => null,
            'authority_generation' => 1,
            'authority_checksum' => hash('sha256', 'authority'),
            'published_set_checksum' => hash('sha256', 'published-set'),
            'published_version_ids' => [$version->id],
            'status' => TicketRuleRun::STATUS_FAILED,
            'termination_reason' => null,
            'limits_json' => ['max_depth' => 8],
            'counters_json' => ['events' => 1, 'actions' => 1],
            'safe_summary_json' => [
                'terminal_status' => TicketRuleRun::STATUS_FAILED,
                'private_summary' => $privateValue,
            ],
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
            'duration_ms' => 1000,
        ], $overrides['run'] ?? []));
        $event = TicketRuleEvent::query()->create(array_merge([
            'run_id' => $run->id,
            'ticket_id' => $ticket->id,
            'sequence' => 1,
            'event_key' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'event_fingerprint' => hash('sha256', 'event-'.Str::uuid()),
            'idempotency_key' => hash('sha256', 'event-key-'.Str::uuid()),
            'source_channel' => 'test',
            'source_action' => 'TicketRuleAdminHistoryTest',
            'changed_fields_json' => ['subject'],
            'before_json' => ['subject' => $privateValue],
            'after_json' => ['subject' => $privateValue],
            'initiator_type' => $initiator ? 'user' : null,
            'initiator_id' => $initiator?->id,
            'automation_actor_id' => null,
            'correlation_uuid' => $run->correlation_uuid,
            'causation_uuid' => null,
            'chain_depth' => 0,
            'status' => TicketRuleEvent::STATUS_NO_CHANGE,
            'occurred_at' => now()->subSecond(),
            'processed_at' => now(),
        ], $overrides['event'] ?? []));
        $execution = TicketRuleExecution::query()->create(array_merge([
            'run_id' => $run->id,
            'event_id' => $event->id,
            'ticket_rule_id' => $rule->id,
            'rule_version_id' => $version->id,
            'order_position' => 1,
            'attempt_number' => 1,
            'retry_of_id' => null,
            'precondition_fingerprint' => hash('sha256', 'precondition'),
            'idempotency_key' => hash('sha256', 'execution-'.Str::uuid()),
            'definition_checksum' => $checksum,
            'status' => TicketRuleExecution::STATUS_FAILED,
            'trigger_relevant' => true,
            'conditions_matched' => false,
            'selected_branch' => 'then',
            'condition_evidence_json' => [
                'valid' => true,
                'passed' => false,
                'mode' => 'grouped',
                'root_match' => 'ALL',
                'groups' => [[
                    'position' => 0,
                    'match' => 'ALL',
                    'passed' => false,
                    'rows' => [[
                        'position' => 0,
                        'field' => 'subject',
                        'operator' => 'equals',
                        'passed' => false,
                        'expected' => $privateValue,
                        'actual' => $privateValue,
                    ]],
                ]],
            ],
            'change_summary_json' => ['subject' => $privateValue],
            'stop_requested' => false,
            'stop_applied' => false,
            'failure_code' => 'private_test_failure',
            'failure_message' => $privateValue,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
            'duration_ms' => 1000,
        ], $overrides['execution'] ?? []));
        $action = TicketRuleActionResult::query()->create(array_merge([
            'run_id' => $run->id,
            'event_id' => $event->id,
            'execution_id' => $execution->id,
            'ticket_id' => $ticket->id,
            'ticket_rule_id' => $rule->id,
            'rule_version_id' => $version->id,
            'branch' => 'then',
            'position' => 0,
            'action_type' => 'set_queue',
            'position_key' => hash('sha256', 'position-'.Str::uuid()),
            'attempt_number' => 1,
            'retry_of_id' => null,
            'precondition_fingerprint' => hash('sha256', 'action-precondition'),
            'idempotency_key' => hash('sha256', 'action-'.Str::uuid()),
            'action_snapshot_json' => [
                'type' => 'set_queue',
                'input' => ['body' => $privateValue],
            ],
            'status' => TicketRuleActionResult::STATUS_FAILED,
            'change_json' => ['subject' => $privateValue],
            'authorization_json' => ['allowed' => false],
            'failure_code' => 'private_action_failure',
            'failure_message' => $privateValue,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
            'duration_ms' => 1000,
        ], $overrides['action'] ?? []));

        return compact('ticket', 'rule', 'version', 'run', 'action');
    }

    private function ticket(string $key): Ticket
    {
        return Ticket::factory()->create([
            'ticket_key' => $key,
            'work_context_id' => app(ResolveWorkContext::class)->internal()->id,
            'queue_id' => $this->defaults['queue']->id,
            'priority_id' => $this->defaults['priority']->id,
            'subject' => 'History test Ticket',
            'description' => 'Safe fixture description.',
            'channel' => 'manual',
        ]);
    }

    private function portalOnlyUser(): User
    {
        $client = Client::factory()->create();
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Customer Portal Viewer',
        ]);
        $user = User::factory()->create([
            'contact_id' => $contact->id,
            'status' => User::STATUS_ACTIVE,
        ]);
        $account = CustomerPortalAccount::query()->create([
            'user_id' => $user->id,
            'contact_id' => $contact->id,
            'status' => CustomerPortalAccount::STATUS_ACTIVE,
        ]);
        CustomerPortalMembership::query()->create([
            'customer_portal_account_id' => $account->id,
            'client_id' => $client->id,
            'site_id' => null,
            'role' => CustomerPortalMembership::ROLE_VIEWER,
            'status' => CustomerPortalMembership::STATUS_ACTIVE,
        ]);

        return $user->refresh();
    }

    /** @param list<string> $permissions */
    private function operator(array $permissions): User
    {
        $operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $operator->givePermissionTo($permissions);

        return $operator->refresh();
    }
}
