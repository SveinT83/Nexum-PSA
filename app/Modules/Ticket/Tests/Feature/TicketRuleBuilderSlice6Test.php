<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\Ticket\Actions\BackfillTicketRuleCompatibilityVersions;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\MutateLegacyTicketRuleCatalog;
use App\Modules\Ticket\Actions\PublishTicketRuleDraft;
use App\Modules\Ticket\Actions\SaveTicketRuleDraft;
use App\Modules\Ticket\Actions\SetPublishedTicketRuleEnabled;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Livewire\Admin\RuleBuilder;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Models\TicketWorkflowVersion;
use App\Modules\Ticket\Services\TicketCustomFieldTargetValidator;
use App\Modules\Ticket\Services\TicketRuleBuilderCatalog;
use App\Modules\Ticket\Services\TicketRuleFrozenPublishedSet;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TicketRuleBuilderSlice6Test extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    /** @var array<string, mixed> */
    private array $defaults;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaults = app(EnsureTicketDefaults::class)->handle();
        app(TicketRuleAutomationActor::class)->resolve();

        $this->manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        foreach (['ticket.manage_rules', 'ticket.rule_publish'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->manager->givePermissionTo(['ticket.manage_rules', 'ticket.rule_publish']);
        $this->actingAs($this->manager);

        config()->set('ticket_rules.v2_enabled', false);
    }

    #[Test]
    public function catalog_hides_ticket_subjects_without_ticket_view_and_collapses_transition_keys(): void
    {
        $secret = 'private-subject-'.str_repeat('x', 16);
        $ticket = Ticket::factory()->create([
            'subject' => $secret,
            'work_context_id' => app(ResolveWorkContext::class)->internal()->id,
        ]);

        foreach (['First Workflow', 'Second Workflow'] as $index => $name) {
            $workflow = TicketWorkflow::query()->create([
                'name' => $name,
                'slug' => 'catalog-workflow-'.$index,
                'is_active' => true,
                'is_default' => false,
                'sort_order' => $index,
            ]);
            TicketWorkflowVersion::query()->create([
                'ticket_workflow_id' => $workflow->id,
                'version' => $index + 1,
                'status' => 'published',
                'definition' => [
                    'states' => [['state_key' => 'open', 'name' => 'Open']],
                    'transitions' => [[
                        'transition_key' => 'resolve',
                        'name' => $index === 0 ? 'Resolve' : 'Complete',
                    ]],
                ],
                'published_by' => $this->manager->id,
                'published_at' => now(),
            ]);
        }

        $catalog = app(TicketRuleBuilderCatalog::class)->get();

        $this->assertSame([], $catalog['tickets']);
        $this->assertStringNotContainsString($secret, json_encode($catalog, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString((string) $ticket->ticket_key, json_encode($catalog, JSON_THROW_ON_ERROR));
        $resolveTransitions = collect($catalog['workflow_transitions'])
            ->where('value', 'resolve')
            ->values();
        $this->assertCount(1, $resolveTransitions);
        $this->assertStringContainsString(
            "exact key on the Ticket's current Workflow",
            (string) $resolveTransitions[0]['label'],
        );
        $this->assertStringNotContainsString(' v1', (string) $resolveTransitions[0]['label']);
        $this->assertTrue(collect($catalog['actions'])->every(
            fn (array $action): bool => isset($action['permitted_triggers'])
                && is_array($action['permitted_triggers']),
        ));

        Permission::findOrCreate('ticket.view', 'web');
        $this->manager->givePermissionTo('ticket.view');
        $this->manager->unsetRelation('permissions');

        $visible = app(TicketRuleBuilderCatalog::class)->get();
        $this->assertTrue(collect($visible['tickets'])->contains('value', $ticket->id));
        $this->assertStringContainsString($secret, json_encode($visible['tickets'], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function preview_renders_safe_draft_evidence_and_an_honest_created_queue_baseline(): void
    {
        foreach (['ticket.view', 'ticket.rule_preview'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->manager->givePermissionTo(['ticket.view', 'ticket.rule_preview']);
        $this->manager->unsetRelation('permissions');
        $this->enableCreatedPublication();

        $privateSubject = 'private-preview-subject-'.str_repeat('p', 24);
        $ticket = Ticket::factory()->create([
            'subject' => $privateSubject,
            'description' => 'Private preview description.',
            'channel' => 'email',
            'work_context_id' => app(ResolveWorkContext::class)->internal()->id,
        ]);
        $before = [
            'ticket' => (array) \Illuminate\Support\Facades\DB::table('tickets')
                ->where('id', $ticket->id)->first(),
            'runs' => \Illuminate\Support\Facades\DB::table('ticket_rule_runs')->count(),
            'actions' => \Illuminate\Support\Facades\DB::table('ticket_rule_action_results')->count(),
            'events' => \Illuminate\Support\Facades\DB::table('ticket_events')->count(),
        ];

        $component = Livewire::test(RuleBuilder::class, ['ruleId' => null])
            ->set('previewTicketId', $ticket->id)
            ->call('preview')
            ->assertSet('error', null)
            ->assertSee('Draft rule result')
            ->assertSee('Condition evaluation')
            ->assertSee('Authorization and policy outcomes')
            ->assertSee('Published rules baseline')
            ->assertSee('current draft is not injected')
            ->assertSee('Rule plan')
            ->assertSee('Event chain')
            ->assertSee('Collisions and overwrites');

        $createdResult = $component->get('previewResult');
        $this->assertSame('published_rules_only', $createdResult['queue_scope']);
        $this->assertIsArray($createdResult['queue']);
        $this->assertStringNotContainsString(
            $privateSubject,
            json_encode($createdResult, JSON_THROW_ON_ERROR),
        );

        $component
            ->call('setTrigger', 'ticket.updated')
            ->set('previewContext.changed_fields', ['subject'])
            ->call('preview')
            ->assertSet('error', null)
            ->assertSee('Exact downstream queue planning is available only for Ticket Created')
            ->assertDontSee('Published rules baseline');

        $this->assertNull($component->get('previewResult')['queue_scope']);
        $this->assertNull($component->get('previewResult')['queue']);
        $after = [
            'ticket' => (array) \Illuminate\Support\Facades\DB::table('tickets')
                ->where('id', $ticket->id)->first(),
            'runs' => \Illuminate\Support\Facades\DB::table('ticket_rule_runs')->count(),
            'actions' => \Illuminate\Support\Facades\DB::table('ticket_rule_action_results')->count(),
            'events' => \Illuminate\Support\Facades\DB::table('ticket_events')->count(),
        ];
        $this->assertEquals($before, $after);

        try {
            $component->set('previewResult', ['terminal_status' => 'forged']);
            $this->fail('A client must not replace server-presented preview evidence.');
        } catch (CannotUpdateLockedPropertyException) {
            $this->assertSame('no_change', $component->get('previewResult')['terminal_status']);
        }
    }

    #[Test]
    public function builder_normalizes_dependent_state_preserves_text_and_dispatches_focus_targets(): void
    {
        $component = Livewire::test(RuleBuilder::class, ['ruleId' => null])
            ->set('conditionMode', 'grouped')
            ->call('addCondition', 0)
            ->assertDispatched('ticket-rule-builder-focus')
            ->set('groups.0.conditions.0.value', '00123')
            ->set('groups.0.conditions.0.field', 'client_id')
            ->assertSet('groups.0.conditions.0.value', '')
            ->set('groups.0.conditions.0.field', 'subject')
            ->set('groups.0.conditions.0.value', '00123')
            ->call('addAction', 'then')
            ->assertDispatched('ticket-rule-builder-focus')
            ->call('setActionType', 'then', 0, 'set_ticket_fields')
            ->set('thenActions.0.input._field_value', 'stale-reference')
            ->set('thenActions.0.input._field_key', 'subject')
            ->assertSet('thenActions.0.input._field_value', '')
            ->assertSeeHtml('data-bs-toggle="collapse"')
            ->assertSeeHtml('aria-expanded="false"');

        $component
            ->set('thenActions.0.input._field_value', '00123')
            ->call('saveDraft')
            ->assertSet('expectedNoDraft', false)
            ->assertSet('error', null);

        $rule = TicketRule::query()->findOrFail((int) $component->get('ruleId'));
        $this->assertSame(
            '00123',
            data_get($rule->draft_payload_json, 'definition.then_actions.0.input.fields.subject'),
        );

        $component
            ->call('setActionType', 'then', 0, 'select_workflow')
            ->call('setTrigger', 'ticket.updated')
            ->assertSee('not valid for the selected trigger')
            ->assertSee('preserved and read-only');
    }

    #[Test]
    public function server_owned_identity_is_locked_and_definition_cannot_be_called(): void
    {
        $first = $this->legacyRule('Locked first');
        $second = $this->legacyRule('Locked second');

        try {
            Livewire::test(RuleBuilder::class, ['ruleId' => $first->id])
                ->set('ruleId', $second->id);
            $this->fail('A client must not replace the locked Rule identity.');
        } catch (CannotUpdateLockedPropertyException) {
            $this->assertNull($first->fresh()->draft_checksum);
        }

        try {
            Livewire::test(RuleBuilder::class, ['ruleId' => null])
                ->set('creationToken', (string) Str::uuid());
            $this->fail('A client must not replace the locked draft creation token.');
        } catch (CannotUpdateLockedPropertyException) {
            $this->assertSame(0, TicketRule::query()->whereNotNull('draft_creation_token')->count());
        }

        $this->expectException(\Livewire\Exceptions\MethodNotFoundException::class);
        Livewire::test(RuleBuilder::class, ['ruleId' => $first->id])
            ->call('definition');
    }

    #[Test]
    public function inaccessible_custom_field_targets_are_not_hydrated_or_published(): void
    {
        Permission::findOrCreate('ticket.update', 'web');
        $field = CustomFieldDefinition::query()->create([
            'model_type' => Ticket::class,
            'key' => 'restricted_rule_field',
            'label' => 'Restricted Rule Field',
            'field_type' => CustomFieldDefinition::TYPE_TEXT,
            'visible_in_ui' => true,
            'editable_in_ui' => true,
            'editable_via_api' => true,
            'searchable' => false,
            'unique_per_model' => false,
            'required' => false,
            'admin_only' => false,
            'view_permission' => 'ticket.update',
            'edit_permission' => 'ticket.update',
            'active' => true,
        ]);
        $target = app(TicketCustomFieldTargetValidator::class)->targetFor($field);
        $secret = 'restricted-condition-'.str_repeat('q', 24);
        $definition = $this->schema2Definition();
        $definition['conditions'] = [
            'mode' => 'grouped',
            'match' => 'ALL',
            'groups' => [[
                'match' => 'ALL',
                'conditions' => [[
                    'field' => 'custom_field.current',
                    'target' => $target,
                    'operator' => 'equals',
                    'value' => $secret,
                ]],
            ]],
        ];
        $draft = app(SaveTicketRuleDraft::class)->handle(
            null,
            $this->draftPayload('Restricted Custom Field', $definition),
            $this->manager,
            creationToken: (string) Str::uuid(),
        );

        $catalog = app(TicketRuleBuilderCatalog::class)->get();
        $this->assertFalse(collect($catalog['custom_fields'])->contains('value', $field->id));
        $response = $this->get(route('tech.admin.settings.tickets.rules.edit', $draft));
        $response->assertForbidden();
        $this->assertStringNotContainsString($secret, (string) $response->getContent());
        $this->assertStringNotContainsString(
            'restricted-condition',
            json_encode($catalog, JSON_THROW_ON_ERROR),
        );

        $this->enableCreatedPublication();
        $beforeVersions = TicketRuleVersion::query()->count();
        try {
            app(PublishTicketRuleDraft::class)->handle(
                $draft,
                $this->manager,
                (string) $draft->draft_checksum,
            );
            $this->fail('A publisher must not use a Custom Field they cannot view.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('definition', $exception->errors());
        }
        $this->assertSame($beforeVersions, TicketRuleVersion::query()->count());
        $this->assertNotNull($draft->fresh()->draft_payload_json);

        $authorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $authorized->givePermissionTo([
            'ticket.manage_rules',
            'ticket.rule_publish',
            'ticket.update',
        ]);
        $this->actingAs($authorized);

        $authorizedCatalog = app(TicketRuleBuilderCatalog::class)->get();
        $this->assertTrue(
            collect($authorizedCatalog['custom_fields'])->contains('value', $field->id),
        );
        Livewire::test(RuleBuilder::class, ['ruleId' => $draft->id])
            ->assertSee('Restricted Rule Field');

        $version = app(PublishTicketRuleDraft::class)->handle(
            $draft->fresh(),
            $authorized,
            (string) $draft->fresh()->draft_checksum,
        );
        $this->assertSame(
            TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            (int) $version->definition_schema_version,
        );
    }

    #[Test]
    public function unknown_nodes_never_hydrate_raw_values_and_round_trip_from_authority(): void
    {
        $secret = 'private-unknown-value-'.str_repeat('z', 24);
        $definition = $this->schema2Definition();
        $definition['trigger_filters'] = [
            'future_filter' => ['private_value' => $secret],
        ];
        $definition['conditions'] = [
            'mode' => 'grouped',
            'match' => 'ALL',
            'groups' => [
                [
                    'match' => 'ALL',
                    'conditions' => [[
                        'field' => 'subject',
                        'operator' => 'contains',
                        'value' => 'group-before',
                    ]],
                ],
                [
                    'match' => 'ALL',
                    'conditions' => [
                        [
                            'field' => 'subject',
                            'operator' => 'contains',
                            'value' => 'safe-before',
                        ],
                        [
                            'field' => 'future.private_fact',
                            'operator' => 'equals',
                            'value' => $secret,
                        ],
                        [
                            'field' => 'subject',
                            'operator' => 'contains',
                            'value' => 'safe-after',
                        ],
                    ],
                ],
                [
                    'match' => 'ALL',
                    'conditions' => [[
                        'field' => 'subject',
                        'operator' => 'contains',
                        'value' => 'group-after',
                    ]],
                ],
            ],
        ];
        $definition['then_actions'] = [
            [
                'type' => 'set_queue',
                'input' => ['queue_id' => (int) $this->defaults['queue']->id],
            ],
            [
                'type' => 'future_private_action',
                'input' => ['opaque_value' => $secret],
            ],
            [
                'type' => 'set_queue',
                'input' => ['queue_id' => (int) $this->defaults['queue']->id],
            ],
        ];
        $definition['future_root'] = ['private_value' => $secret];

        $draft = app(SaveTicketRuleDraft::class)->handle(
            null,
            $this->draftPayload('Unknown node privacy', $definition),
            $this->manager,
            creationToken: (string) Str::uuid(),
        );
        $component = Livewire::test(RuleBuilder::class, ['ruleId' => $draft->id])
            ->assertDontSee($secret, false)
            ->assertDontSee('future_private_action', false)
            ->assertSee('Unsupported action')
            ->assertSee('preserved exactly and read-only');

        $hydratedState = json_encode([
            'descriptors' => $component->get('unknownDescriptors'),
            'groups' => $component->get('groups'),
            'then_actions' => $component->get('thenActions'),
            'trigger_filters' => $component->get('triggerFilters'),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secret, $hydratedState);
        $this->assertStringNotContainsString('future_private_action', $hydratedState);
        $this->assertStringNotContainsString('opaque_value', $hydratedState);
        $this->assertStringNotContainsString('future_filter', $hydratedState);
        $groupsBefore = $component->get('groups');
        $actionsBefore = $component->get('thenActions');
        $component->call('moveGroup', 1, 1);
        $this->assertSame($groupsBefore, $component->get('groups'));
        $component->call('removeGroup', 1);
        $this->assertSame($groupsBefore, $component->get('groups'));
        $component->call('moveGroup', 0, 1);
        $this->assertSame($groupsBefore, $component->get('groups'));
        $component->call('removeGroup', 0);
        $this->assertSame($groupsBefore, $component->get('groups'));

        $component->call('moveCondition', 1, 0, 1);
        $this->assertSame($groupsBefore, $component->get('groups'));
        $component->call('removeCondition', 1, 0);
        $this->assertSame($groupsBefore, $component->get('groups'));
        $component->call('moveAction', 'then', 0, 1);
        $this->assertSame($actionsBefore, $component->get('thenActions'));
        $component->call('removeAction', 'then', 0);
        $this->assertSame($actionsBefore, $component->get('thenActions'));

        $component
            ->set('name', 'Unknown node privacy saved')
            ->call('saveDraft')
            ->assertSet('error', null);

        $saved = $draft->fresh();
        $this->assertEquals(
            $definition,
            data_get($saved->draft_payload_json, 'definition'),
            'Unsupported nodes must be re-read from the authoritative draft and preserved losslessly.',
        );
        $savedChecksum = (string) $saved->draft_checksum;
        $reordered = Livewire::test(RuleBuilder::class, ['ruleId' => $draft->id]);
        $reorderedActions = $reordered->get('thenActions');
        [$reorderedActions[0], $reorderedActions[1]] = [$reorderedActions[1], $reorderedActions[0]];
        $reordered
            ->set('thenActions', $reorderedActions)
            ->call('saveDraft');
        $this->assertStringStartsWith(
            'The operation could not be completed. Reference: ',
            (string) $reordered->get('error'),
        );
        $this->assertSame($savedChecksum, (string) $draft->fresh()->draft_checksum);

        $component
            ->set('thenActions', [[
                '_key' => 'crafted-replacement',
                'type' => 'set_queue',
                'input' => ['queue_id' => (int) $this->defaults['queue']->id],
            ]])
            ->call('saveDraft');

        $this->assertStringStartsWith(
            'The operation could not be completed. Reference: ',
            (string) $component->get('error'),
        );
        $this->assertSame($savedChecksum, (string) $draft->fresh()->draft_checksum);
        $this->assertEquals(
            $definition,
            data_get($draft->fresh()->draft_payload_json, 'definition'),
        );

        try {
            Livewire::test(RuleBuilder::class, ['ruleId' => $draft->id])
                ->set('unknownDescriptors', []);
            $this->fail('Opaque unknown-node descriptors must be locked from hydration.');
        } catch (CannotUpdateLockedPropertyException) {
            $this->assertSame($savedChecksum, (string) $draft->fresh()->draft_checksum);
        }
    }

    #[Test]
    public function concurrent_first_draft_and_executable_payloads_fail_without_overwrite(): void
    {
        $rule = $this->legacyRule('Concurrent draft');
        $save = app(SaveTicketRuleDraft::class);
        $firstPayload = $this->draftPayload('First editor', $this->schema2Definition());

        $saved = $save->handle($rule, $firstPayload, $this->manager, null, true);

        try {
            $save->handle(
                $rule,
                $this->draftPayload('Second editor', $this->schema2Definition()),
                $this->manager,
                null,
                true,
            );
            $this->fail('The second first-draft editor must lose the optimistic race.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('draft', $exception->errors());
        }

        $this->assertSame('First editor', $saved->fresh()->draft_payload_json['name']);

        $count = TicketRule::query()->count();
        foreach (['endpoint', 'eval', 'executable', 'headers', 'http', 'javascript', 'shell', 'uri', 'webhook'] as $key) {
            try {
                $save->handle(
                    null,
                    $this->draftPayload('Unsafe '.$key, [
                        'schema_version' => 2,
                        'unknown' => [$key => 'private transport value'],
                    ]),
                    $this->manager,
                    creationToken: (string) Str::uuid(),
                );
                $this->fail("The {$key} key must be rejected.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('draft', $exception->errors());
            }
        }
        $this->assertSame($count, TicketRule::query()->count());
    }

    #[Test]
    public function new_draft_and_publication_refuse_catalog_drift_without_partial_writes(): void
    {
        $save = app(SaveTicketRuleDraft::class);
        $fence = TicketRuleAuthorityFence::query()->findOrFail(TicketRuleAuthorityFence::SCOPE);
        $fence->forceFill(['catalog_checksum' => str_repeat('0', 64)])->save();

        try {
            $save->handle(
                null,
                $this->draftPayload('Drifted create', $this->schema2Definition()),
                $this->manager,
                creationToken: (string) Str::uuid(),
            );
            $this->fail('A new logical rule must not heal pre-existing fence drift.');
        } catch (RuntimeException) {
            $this->assertDatabaseMissing('ticket_rules', ['name' => '[Draft] Drifted create']);
        }

        $fence->forceFill([
            'catalog_checksum' => app(\App\Modules\Ticket\Services\TicketRuleCatalogFingerprint::class)->checksum(),
        ])->save();
        $draft = $save->handle(
            null,
            $this->draftPayload('Drifted publish', $this->schema2Definition()),
            $this->manager,
            creationToken: (string) Str::uuid(),
        );

        $this->enableCreatedPublication();
        $draft->refresh();
        TicketRuleAuthorityFence::query()
            ->whereKey(TicketRuleAuthorityFence::SCOPE)
            ->update(['catalog_checksum' => str_repeat('f', 64)]);
        $beforeVersions = TicketRuleVersion::query()->count();

        try {
            app(PublishTicketRuleDraft::class)->handle(
                $draft,
                $this->manager,
                (string) $draft->draft_checksum,
            );
            $this->fail('Publication must not repair a drifted catalogue.');
        } catch (RuntimeException) {
            $this->assertSame($beforeVersions, TicketRuleVersion::query()->count());
            $this->assertNotNull($draft->fresh()->draft_payload_json);
            $this->assertNull($draft->fresh()->published_version_id);
        }
    }

    #[Test]
    public function index_is_bounded_summarizes_branches_and_keeps_schema_one_on_legacy_toggle(): void
    {
        $rule = $this->legacyRule('Legacy indexed', false, true, [[
            'type' => 'set_queue',
            'value' => (int) $this->defaults['queue']->id,
        ]]);
        $fence = TicketRuleAuthorityFence::query()->findOrFail(TicketRuleAuthorityFence::SCOPE);
        app(BackfillTicketRuleCompatibilityVersions::class)->handle(
            (int) $fence->catalog_generation,
            (string) $fence->catalog_checksum,
            'slice6-index-test',
        );
        $rule->refresh();

        $this->get(route('tech.admin.settings.tickets.rules'))
            ->assertOk()
            ->assertSee('Then 1 · Else 0')
            ->assertSee('Flow: Stop')
            ->assertSeeHtml('aria-sort="ascending"')
            ->assertSee('Sort by name; next direction ascending.');
        Permission::findOrCreate('ticket.update', 'web');
        $this->manager->givePermissionTo('ticket.update');
        $this->manager->unsetRelation('permissions');

        $this->post(route('tech.admin.settings.tickets.rules.toggle', $rule))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($rule->fresh()->is_active);
        $this->assertSame(TicketRule::COMPATIBILITY_ELIGIBLE, $rule->compatibility_status);

        $this->get(route('tech.admin.settings.tickets.rules', [
            'search' => str_repeat('x', 151),
            'lifecycle' => 'arbitrary',
            'sort' => 'raw_sql',
        ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['search', 'lifecycle', 'sort']);
    }

    #[Test]
    public function schema_two_publish_stays_inactive_and_default_authority_exposes_no_unsafe_toggle(): void
    {
        $this->enableCreatedPublication();
        $draft = app(SaveTicketRuleDraft::class)->handle(
            null,
            $this->draftPayload('Inactive schema two', $this->schema2Definition()),
            $this->manager,
            creationToken: (string) Str::uuid(),
        );
        $this->get(route('tech.admin.settings.tickets.rules'))
            ->assertOk()
            ->assertSee('Draft only')
            ->assertSee('Publish an immutable version before changing runtime status');
        $this->post(route('tech.admin.settings.tickets.rules.toggle', $draft))
            ->assertRedirect()
            ->assertSessionHasErrors('rule');
        $this->assertFalse($draft->fresh()->is_active);
        $this->assertNotNull($draft->fresh()->draft_payload_json);

        $version = app(PublishTicketRuleDraft::class)->handle(
            $draft,
            $this->manager,
            (string) $draft->draft_checksum,
        );
        $rule = $draft->fresh();

        $this->assertFalse($rule->is_active);
        config()->set('ticket_rules.v2_enabled', false);

        $this->get(route('tech.admin.settings.tickets.rules'))
            ->assertOk()
            ->assertSee('Enable unavailable')
            ->assertSee('Publish does not enable automatically.')
            ->assertSee('Schema 2 status changes remain unavailable');

        $this->post(route('tech.admin.settings.tickets.rules.toggle', $rule), [
            'published_version_id' => $version->id,
            'definition_checksum' => $version->definition_checksum,
            'expected_enabled' => false,
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('rule');

        $this->assertFalse($rule->fresh()->is_active);
        $this->assertSame(TicketRule::COMPATIBILITY_ELIGIBLE, $rule->compatibility_status);
    }

    #[Test]
    public function schema_two_enable_requires_v2_authority_and_both_permissions(): void
    {
        $this->enableCreatedPublication();
        config()->set('ticket_rules.allow_sqlite_mutations_for_tests', true);
        $draft = app(SaveTicketRuleDraft::class)->handle(
            null,
            $this->draftPayload('Explicit schema two enable', $this->schema2Definition()),
            $this->manager,
            creationToken: (string) Str::uuid(),
        );
        $version = app(PublishTicketRuleDraft::class)->handle(
            $draft,
            $this->manager,
            (string) $draft->draft_checksum,
        );
        $rule = $draft->fresh();
        $toggle = app(SetPublishedTicketRuleEnabled::class);

        $this->assertFalse($rule->is_active);
        try {
            $toggle->handle(
                $rule,
                $this->manager,
                (int) $version->id,
                (string) $version->definition_checksum,
                false,
            );
            $this->fail('Legacy authority must never enable a schema-2 publication.');
        } catch (RuntimeException) {
            $this->assertFalse($rule->fresh()->is_active);
        }

        TicketRuleAuthorityFence::query()
            ->whereKey(TicketRuleAuthorityFence::SCOPE)
            ->update(['runtime_authority' => TicketRuleAuthorityFence::AUTHORITY_V2]);

        $this->manager->revokePermissionTo('ticket.rule_publish');
        $this->manager->unsetRelation('permissions');
        try {
            $toggle->handle(
                $rule,
                $this->manager,
                (int) $version->id,
                (string) $version->definition_checksum,
                false,
            );
            $this->fail('Manage Rules alone must not enable runtime execution.');
        } catch (RuntimeException) {
            $this->assertFalse($rule->fresh()->is_active);
        }
        $this->manager->givePermissionTo('ticket.rule_publish');
        $this->manager->unsetRelation('permissions');

        $this->get(route('tech.admin.settings.tickets.rules'))
            ->assertOk()
            ->assertDontSee('Enable unavailable');
        $this->post(route('tech.admin.settings.tickets.rules.toggle', $rule), [
            'published_version_id' => $version->id,
            'definition_checksum' => $version->definition_checksum,
            'expected_enabled' => false,
        ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($rule->fresh()->is_active);
        $this->assertSame(TicketRule::LIFECYCLE_PUBLISHED, $rule->lifecycle_status);
        $this->assertContains(
            (int) $version->id,
            app(TicketRuleFrozenPublishedSet::class)->capture()['version_ids'],
        );

        $this->post(route('tech.admin.settings.tickets.rules.toggle', $rule), [
            'published_version_id' => $version->id,
            'definition_checksum' => $version->definition_checksum,
            'expected_enabled' => true,
        ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertFalse($rule->fresh()->is_active);
        $this->assertNotContains(
            (int) $version->id,
            app(TicketRuleFrozenPublishedSet::class)->capture()['version_ids'],
        );

        try {
            $toggle->handle(
                $rule->fresh(),
                $this->manager,
                (int) $version->id + 1,
                (string) $version->definition_checksum,
                false,
            );
            $this->fail('A stale immutable version identity must be refused.');
        } catch (ValidationException) {
            $this->assertFalse($rule->fresh()->is_active);
        }

        $fence = TicketRuleAuthorityFence::query()
            ->findOrFail(TicketRuleAuthorityFence::SCOPE);
        $generation = (int) $fence->catalog_generation;
        $fence->forceFill(['catalog_checksum' => str_repeat('d', 64)])->save();
        try {
            $toggle->handle(
                $rule->fresh(),
                $this->manager,
                (int) $version->id,
                (string) $version->definition_checksum,
                false,
            );
            $this->fail('A drifted catalog fence must be refused without repair.');
        } catch (ValidationException) {
            $this->assertFalse($rule->fresh()->is_active);
            $this->assertSame($generation, (int) $fence->fresh()->catalog_generation);
            $this->assertSame(str_repeat('d', 64), $fence->fresh()->catalog_checksum);
        }
    }

    #[Test]
    public function stable_creation_token_makes_new_draft_retries_idempotent(): void
    {
        $save = app(SaveTicketRuleDraft::class);
        $token = (string) Str::uuid();
        $payload = $this->draftPayload('Idempotent new draft', $this->schema2Definition());
        $beforeCount = TicketRule::withTrashed()->count();

        $first = $save->handle(
            null,
            $payload,
            $this->manager,
            creationToken: $token,
        );
        $generation = (int) TicketRuleAuthorityFence::query()
            ->findOrFail(TicketRuleAuthorityFence::SCOPE)
            ->catalog_generation;
        $updatedAt = $first->draft_updated_at?->format('Y-m-d H:i:s.u');

        $retry = $save->handle(
            null,
            $payload,
            $this->manager,
            creationToken: $token,
        );

        $this->assertSame($first->id, $retry->id);
        $this->assertSame($token, $retry->draft_creation_token);
        $this->assertSame($beforeCount + 1, TicketRule::withTrashed()->count());
        $this->assertSame($updatedAt, $retry->draft_updated_at?->format('Y-m-d H:i:s.u'));
        $this->assertSame(
            $generation,
            (int) TicketRuleAuthorityFence::query()
                ->findOrFail(TicketRuleAuthorityFence::SCOPE)
                ->catalog_generation,
        );

        try {
            $save->handle(
                null,
                $this->draftPayload('Different payload', $this->schema2Definition()),
                $this->manager,
                creationToken: $token,
            );
            $this->fail('A creation token cannot be reused for a different payload.');
        } catch (ValidationException) {
            $this->assertSame($first->draft_checksum, $first->fresh()->draft_checksum);
            $this->assertSame($beforeCount + 1, TicketRule::withTrashed()->count());
        }

        $this->expectException(ValidationException::class);
        $save->handle(null, $payload, $this->manager);
    }

    #[Test]
    public function hydrated_operations_and_render_reauthorize_the_active_manager(): void
    {
        $inactive = Livewire::test(RuleBuilder::class, ['ruleId' => null]);
        $this->manager->forceFill(['status' => User::STATUS_DISABLED])->save();
        try {
            app(RuleBuilder::class)->render();
            $this->fail('Render must reauthorize a manager whose account was disabled.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $inactive->call('$refresh')->assertForbidden();

        $this->manager->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $this->manager->refresh();
        $revoked = Livewire::test(RuleBuilder::class, ['ruleId' => null]);
        $this->manager->revokePermissionTo('ticket.manage_rules');
        $this->manager->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $revoked->call('addGroup')->assertForbidden();
    }

    #[Test]
    public function workflow_and_custom_field_catalogs_are_bounded_and_payload_safe(): void
    {
        $oversizedWorkflowVersionId = null;
        for ($index = 0; $index <= TicketRuleBuilderCatalog::MAX_WORKFLOW_VERSIONS; $index++) {
            $workflow = TicketWorkflow::query()->create([
                'name' => $index === TicketRuleBuilderCatalog::MAX_WORKFLOW_VERSIONS
                    ? str_repeat('W', 200)
                    : 'Bounded Workflow '.$index,
                'slug' => 'bounded-workflow-'.$index,
                'is_active' => true,
                'is_default' => false,
                'sort_order' => $index,
            ]);
            $oversized = $index === TicketRuleBuilderCatalog::MAX_WORKFLOW_VERSIONS;
            $states = $oversized
                ? array_merge(
                    [['state_key' => str_repeat('s', 192), 'name' => str_repeat('S', 400)]],
                    collect(range(0, TicketRuleBuilderCatalog::MAX_WORKFLOW_STATES))
                        ->map(fn (int $state): array => [
                            'state_key' => 'state-'.$state,
                            'name' => str_repeat('S', 400).$state,
                        ])->all(),
                )
                : [['state_key' => 'open', 'name' => 'Open']];
            $transitions = $oversized
                ? array_merge(
                    [['transition_key' => str_repeat('t', 192), 'name' => str_repeat('T', 400)]],
                    collect(range(0, TicketRuleBuilderCatalog::MAX_WORKFLOW_TRANSITIONS))
                        ->map(fn (int $transition): array => [
                            'transition_key' => 'transition-'.$index.'-'.$transition,
                            'name' => str_repeat('T', 400).$transition,
                        ])->all(),
                )
                : [['transition_key' => 'transition-'.$index, 'name' => 'Transition '.$index]];

            $version = TicketWorkflowVersion::query()->create([
                'ticket_workflow_id' => $workflow->id,
                'version' => 1,
                'status' => 'published',
                'definition' => ['states' => $states, 'transitions' => $transitions],
                'published_by' => $this->manager->id,
                'published_at' => now(),
            ]);
            if ($oversized) {
                $oversizedWorkflowVersionId = $version->id;
            }
        }

        for ($index = 0; $index <= TicketRuleBuilderCatalog::MAX_CUSTOM_FIELDS; $index++) {
            CustomFieldDefinition::query()->create([
                'model_type' => Ticket::class,
                'key' => 'restricted_bounded_field_'.$index,
                'label' => sprintf('Restricted field %03d', $index),
                'field_type' => CustomFieldDefinition::TYPE_TEXT,
                'visible_in_ui' => true,
                'editable_in_ui' => true,
                'editable_via_api' => true,
                'searchable' => false,
                'unique_per_model' => false,
                'required' => false,
                'admin_only' => true,
                'active' => true,
            ]);
        }
        $restrictedCatalog = app(TicketRuleBuilderCatalog::class)->get();
        $this->assertSame([], $restrictedCatalog['custom_fields']);
        $this->assertFalse($restrictedCatalog['limits']['custom_fields_truncated']);
        $this->assertStringNotContainsString(
            'Restricted field',
            json_encode($restrictedCatalog, JSON_THROW_ON_ERROR),
        );

        $boundedFieldId = null;
        for ($index = 0; $index <= TicketRuleBuilderCatalog::MAX_CUSTOM_FIELDS; $index++) {
            $oversized = $index === 0;
            $options = $oversized
                ? array_merge(
                    [['value' => str_repeat('o', 192), 'label' => str_repeat('O', 400)]],
                    collect(range(0, TicketRuleBuilderCatalog::MAX_CUSTOM_FIELD_OPTIONS))
                        ->map(fn (int $option): array => [
                            'value' => 'option-'.$option,
                            'label' => str_repeat('O', 400).$option,
                        ])->all(),
                )
                : [['value' => 'one', 'label' => 'One']];
            $field = CustomFieldDefinition::query()->create([
                'model_type' => Ticket::class,
                'key' => 'bounded_field_'.$index,
                'label' => $oversized ? '000 '.str_repeat('C', 200) : sprintf('%03d Field', $index),
                'field_type' => CustomFieldDefinition::TYPE_SELECT,
                'options' => $options,
                'visible_in_ui' => true,
                'editable_in_ui' => true,
                'editable_via_api' => true,
                'searchable' => false,
                'unique_per_model' => false,
                'required' => false,
                'admin_only' => false,
                'active' => true,
            ]);
            if ($oversized) {
                $boundedFieldId = $field->id;
            }
        }

        $catalog = app(TicketRuleBuilderCatalog::class)->get();
        $workflowVersions = collect($catalog['references']['ticket_workflow_version.published']);
        $customFields = collect($catalog['custom_fields']);

        $this->assertTrue($catalog['limits']['workflow_versions_truncated']);
        $this->assertTrue($catalog['limits']['custom_fields_truncated']);
        $this->assertLessThanOrEqual(TicketRuleBuilderCatalog::MAX_WORKFLOW_VERSIONS, $workflowVersions->count());
        $this->assertLessThanOrEqual(TicketRuleBuilderCatalog::MAX_CUSTOM_FIELDS, $customFields->count());
        $boundedWorkflow = $workflowVersions->firstWhere('value', $oversizedWorkflowVersionId);
        $this->assertNotNull($boundedWorkflow);
        $this->assertLessThanOrEqual(TicketRuleBuilderCatalog::MAX_WORKFLOW_STATES, count($boundedWorkflow['states']));
        $this->assertLessThanOrEqual(TicketRuleBuilderCatalog::MAX_WORKFLOW_TRANSITIONS, count($boundedWorkflow['transitions']));
        $this->assertFalse(collect($boundedWorkflow['states'])->contains('value', str_repeat('s', 192)));
        $this->assertFalse(collect($boundedWorkflow['transitions'])->contains('value', str_repeat('t', 192)));
        $boundedField = $customFields->firstWhere('value', $boundedFieldId);
        $this->assertNotNull($boundedField);
        $this->assertLessThanOrEqual(TicketRuleBuilderCatalog::MAX_CUSTOM_FIELD_OPTIONS, count($boundedField['options']));
        $this->assertFalse(collect($boundedField['options'])->contains('value', str_repeat('o', 192)));
        $this->assertLessThanOrEqual(160, mb_strlen((string) $boundedWorkflow['label']));
        $this->assertLessThanOrEqual(160, mb_strlen((string) $boundedField['label']));
        $this->assertTrue(collect($boundedWorkflow['states'])->every(
            fn (array $state): bool => mb_strlen((string) $state['label']) <= 160,
        ));
        $this->assertTrue(collect($boundedField['options'])->every(
            fn (array $option): bool => mb_strlen((string) $option['label']) <= 160,
        ));
        $specialized = json_encode([
            $catalog['workflow_transitions'],
            $catalog['references']['ticket_workflow_version.published'],
            $catalog['custom_fields'],
        ], JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual(TicketRuleBuilderCatalog::MAX_SPECIALIZED_CATALOG_BYTES, strlen($specialized));
        Livewire::test(RuleBuilder::class, ['ruleId' => null])
            ->assertSee('Selector results are bounded for safe loading.');
    }

    private function legacyRule(
        string $name,
        bool $active = false,
        bool $stop = false,
        array $actions = [],
    ): TicketRule {
        return app(MutateLegacyTicketRuleCatalog::class)->create([
            'name' => $name,
            'description' => 'Slice 6 legacy compatibility fixture.',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => 100,
            'is_active' => $active,
            'stop_processing' => $stop,
            'conditions_json' => [],
            'actions_json' => $actions,
            'created_by' => $this->manager->id,
            'updated_by' => $this->manager->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function draftPayload(string $name, array $definition): array
    {
        return [
            'name' => $name,
            'description' => 'Typed Ticket Rule draft.',
            'definition' => $definition,
        ];
    }

    /** @return array<string, mixed> */
    private function schema2Definition(): array
    {
        return [
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => 'ticket.created',
            'trigger_filters' => [],
            'conditions' => [
                'mode' => 'always',
                'match' => 'ALL',
                'groups' => [],
            ],
            'then_actions' => [],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 100],
        ];
    }

    private function enableCreatedPublication(): void
    {
        config()->set('ticket_rules.v2_enabled', true);
        $triggers = (array) config('ticket_rules.capabilities.triggers', []);
        $triggers['ticket.created'] = true;
        config()->set('ticket_rules.capabilities.triggers', $triggers);
    }
}
