<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Ticket\Actions\BackfillTicketRuleCompatibilityVersions;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\InspectTicketRuleCompatibility;
use App\Modules\Ticket\Actions\LegacyTicketRuleMutationBoundary;
use App\Modules\Ticket\Actions\MutateLegacyTicketRuleCatalog;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketRuleCatalogMutationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function all_four_current_controller_writes_advance_the_legacy_catalogue_fence(): void
    {
        $techRole = Role::findOrCreate('Tech', 'web');
        $adminRole = Role::findOrCreate('Admin', 'web');
        foreach (['ticket.manage_rules', 'ticket.rule_publish', 'ticket.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $adminRole->givePermissionTo(['ticket.manage_rules', 'ticket.rule_publish', 'ticket.update']);

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole([$techRole, $adminRole]);
        $defaults = app(EnsureTicketDefaults::class)->handle();

        $payload = [
            'name' => 'Controller fenced rule',
            'description' => 'Every current write uses one boundary.',
            'weight' => 10,
            'is_active' => '1',
            'stop_processing' => '0',
            'conditions' => [[
                'field' => 'channel',
                'operator' => 'equals',
                'value' => 'email',
            ]],
            'actions' => [[
                'type' => 'set_ticket_type',
                'value' => (string) $defaults['type']->id,
            ]],
        ];

        $generation = $this->generation();

        $this->actingAs($admin)
            ->post(route('tech.admin.settings.tickets.rules.store'), $payload)
            ->assertRedirect(route('tech.admin.settings.tickets.rules'));

        $rule = TicketRule::query()->where('name', 'Controller fenced rule')->firstOrFail();
        $this->assertSame(++$generation, $this->generation());
        $this->assertSame(TicketRule::COMPATIBILITY_UNVERSIONED, $rule->compatibility_status);

        $preflight = app(InspectTicketRuleCompatibility::class)->handle();
        app(BackfillTicketRuleCompatibilityVersions::class)->handle(
            $preflight['catalog_generation'],
            $preflight['catalog_checksum'],
            'legacy-boundary-test',
        );
        $rule->refresh();
        $this->assertSame(
            TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            $rule->publishedVersion()->firstOrFail()->definition_schema_version,
        );
        $this->assertSame($generation, $this->generation());

        $this->actingAs($admin)
            ->put(route('tech.admin.settings.tickets.rules.update', $rule), array_replace($payload, [
                'weight' => 20,
            ]))
            ->assertRedirect(route('tech.admin.settings.tickets.rules'));

        $this->assertSame(++$generation, $this->generation());
        $this->assertSame(20, $rule->refresh()->weight);

        $this->actingAs($admin)
            ->post(route('tech.admin.settings.tickets.rules.toggle', $rule))
            ->assertRedirect();

        $this->assertSame(++$generation, $this->generation());
        $this->assertFalse($rule->refresh()->is_active);

        $this->actingAs($admin)
            ->delete(route('tech.admin.settings.tickets.rules.destroy', $rule))
            ->assertRedirect();

        $this->assertSame(++$generation, $this->generation());
        $deleted = TicketRule::withTrashed()->findOrFail($rule->id);
        $this->assertTrue($deleted->trashed());
        $this->assertSame(TicketRule::LIFECYCLE_DELETED, $deleted->lifecycle_status);
        $this->assertSame(
            TicketRuleAuthorityFence::AUTHORITY_LEGACY,
            TicketRuleAuthorityFence::query()->findOrFail(TicketRuleAuthorityFence::SCOPE)->runtime_authority,
        );
    }

    #[Test]
    public function manage_rules_alone_cannot_reach_any_legacy_write_route(): void
    {
        Permission::findOrCreate('ticket.rule_publish', 'web');
        $operator = $this->operator(['ticket.manage_rules']);
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $rule = app(MutateLegacyTicketRuleCatalog::class)->create(
            $this->ruleAttributes((int) $defaults['type']->id),
        );
        $payload = $this->requestPayload((int) $defaults['type']->id);
        $before = $this->catalogState($rule);

        $this->actingAs($operator)
            ->post(route('tech.admin.settings.tickets.rules.store'), $payload)
            ->assertForbidden();
        $this->actingAs($operator)
            ->put(route('tech.admin.settings.tickets.rules.update', $rule), $payload)
            ->assertForbidden();
        $this->actingAs($operator)
            ->post(route('tech.admin.settings.tickets.rules.toggle', $rule))
            ->assertForbidden();
        $this->actingAs($operator)
            ->delete(route('tech.admin.settings.tickets.rules.destroy', $rule))
            ->assertForbidden();

        $this->assertSame($before, $this->catalogState($rule));
    }

    #[Test]
    public function legacy_store_and_update_require_current_action_permissions_and_revocations_fail_closed(): void
    {
        foreach (['ticket.update', 'signal.action.execute'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $operator = $this->operator(['ticket.manage_rules', 'ticket.rule_publish']);
        $adminRole = Role::findByName('Admin', 'web');
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $payload = $this->requestPayload((int) $defaults['type']->id);
        $beforeStore = $this->catalogState();

        $this->actingAs($operator)
            ->post(route('tech.admin.settings.tickets.rules.store'), $payload)
            ->assertForbidden();
        $this->assertSame($beforeStore, $this->catalogState());

        $adminRole->givePermissionTo('ticket.update');
        $operator->unsetRelation('roles');
        $this->actingAs($operator)
            ->post(route('tech.admin.settings.tickets.rules.store'), $payload)
            ->assertRedirect(route('tech.admin.settings.tickets.rules'))
            ->assertSessionHasNoErrors();
        $rule = TicketRule::query()->where('name', 'Boundary request rule')->firstOrFail();

        $signalPayload = array_replace($payload, [
            'actions' => [[
                'type' => 'emit_signal',
                'value' => 'security alert',
                'severity' => 'warning',
                'summary' => 'Safe signal summary.',
            ]],
        ]);
        $beforeSignal = $this->catalogState($rule);
        $this->actingAs($operator)
            ->put(route('tech.admin.settings.tickets.rules.update', $rule), $signalPayload)
            ->assertForbidden();
        $this->assertSame($beforeSignal, $this->catalogState($rule));

        $adminRole->givePermissionTo('signal.action.execute');
        $operator->unsetRelation('roles');
        $this->actingAs($operator)
            ->put(route('tech.admin.settings.tickets.rules.update', $rule), $signalPayload)
            ->assertRedirect(route('tech.admin.settings.tickets.rules'))
            ->assertSessionHasNoErrors();
        $this->assertSame('emit_signal', $rule->fresh()->actions_json[0]['type']);

        $staleSignalOperator = User::query()->with('roles.permissions')->findOrFail($operator->id);
        $this->assertTrue($staleSignalOperator->can('signal.action.execute'));
        $adminRole->revokePermissionTo('signal.action.execute');
        $beforeRevokedSignal = $this->catalogState($rule);
        $this->assertAuthorizationRejected(fn () => app(LegacyTicketRuleMutationBoundary::class)->update(
            $staleSignalOperator,
            $rule->fresh(),
            ['actions_json' => $rule->fresh()->actions_json],
        ));
        $this->assertSame($beforeRevokedSignal, $this->catalogState($rule));

        $stalePublisher = User::query()->with('roles.permissions')->findOrFail($operator->id);
        $this->assertTrue($stalePublisher->can('ticket.rule_publish'));
        $adminRole->revokePermissionTo('ticket.rule_publish');
        $beforeRevokedPublish = $this->catalogState($rule);
        $this->assertAuthorizationRejected(
            fn () => app(LegacyTicketRuleMutationBoundary::class)->toggle($stalePublisher, $rule->fresh()),
        );
        $this->assertSame($beforeRevokedPublish, $this->catalogState($rule));

        $adminRole->givePermissionTo('ticket.rule_publish');
        $staleActiveOperator = User::query()->with('roles.permissions')->findOrFail($operator->id);
        DB::table($operator->getTable())->where('id', $operator->id)->update([
            'status' => User::STATUS_DISABLED,
        ]);
        $beforeDisabled = $this->catalogState($rule);
        $this->assertAuthorizationRejected(
            fn () => app(LegacyTicketRuleMutationBoundary::class)->delete($staleActiveOperator, $rule->fresh()),
        );
        $this->assertSame($beforeDisabled, $this->catalogState($rule));
    }

    #[Test]
    public function legacy_signal_enable_requires_current_action_permission_but_disable_remains_available(): void
    {
        Permission::findOrCreate('signal.action.execute', 'web');
        $operator = $this->operator(['ticket.manage_rules', 'ticket.rule_publish']);
        $adminRole = Role::findByName('Admin', 'web');
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $rule = app(MutateLegacyTicketRuleCatalog::class)->create(array_replace(
            $this->ruleAttributes((int) $defaults['type']->id),
            [
                'is_active' => true,
                'actions_json' => [[
                    'type' => 'emit_signal',
                    'value' => 'permission_boundary',
                    'signal_type' => 'permission_boundary',
                ]],
            ],
        ));
        $boundary = app(LegacyTicketRuleMutationBoundary::class);
        $generation = $this->generation();

        $boundary->toggle($operator, $rule);
        $this->assertFalse($rule->fresh()->is_active);
        $this->assertSame(++$generation, $this->generation());

        $beforeDeniedEnable = $this->catalogState($rule);
        $this->assertAuthorizationRejected(fn () => $boundary->toggle($operator, $rule->fresh()));
        $this->assertSame($beforeDeniedEnable, $this->catalogState($rule));
        $this->assertSame($generation, $this->generation());

        $adminRole->givePermissionTo('signal.action.execute');
        $boundary->toggle($operator, $rule->fresh());
        $this->assertTrue($rule->fresh()->is_active);
        $this->assertSame(++$generation, $this->generation());

        $staleEnabledOperator = User::query()->with('roles.permissions')->findOrFail($operator->id);
        $this->assertTrue($staleEnabledOperator->can('signal.action.execute'));
        $adminRole->revokePermissionTo('signal.action.execute');
        $boundary->toggle($staleEnabledOperator, $rule->fresh());
        $this->assertFalse($rule->fresh()->is_active);
        $this->assertSame(++$generation, $this->generation());
    }

    #[Test]
    public function draft_creation_token_is_governed_and_token_only_rows_reject_all_legacy_mutations(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $catalog = app(MutateLegacyTicketRuleCatalog::class);
        $token = (string) Str::uuid();
        $beforeGovernedCreate = $this->catalogState();

        $this->assertLegacyRejected(fn () => $catalog->create(array_replace(
            $this->ruleAttributes((int) $defaults['type']->id),
            ['draft_creation_token' => $token],
        )));
        $this->assertSame($beforeGovernedCreate, $this->catalogState());

        $rule = $catalog->create($this->ruleAttributes((int) $defaults['type']->id));
        $rule->forceFill(['draft_creation_token' => $token])->save();
        $this->assertNull($rule->draft_payload_json);
        $this->assertNull($rule->draft_checksum);
        $this->assertNull($rule->draft_updated_by);
        $this->assertNull($rule->draft_updated_at);
        $beforeMutations = $this->catalogState($rule);

        $this->assertLegacyRejected(fn () => $catalog->update($rule->fresh(), ['weight' => 99]));
        $this->assertLegacyRejected(fn () => $catalog->toggle($rule->fresh()));
        $this->assertLegacyRejected(fn () => $catalog->delete($rule->fresh()));
        $this->assertSame($beforeMutations, $this->catalogState($rule));
    }

    #[Test]
    public function draft_bearing_rules_cannot_be_updated_toggled_deleted_or_created_through_legacy_boundaries(): void
    {
        $operator = $this->operator([
            'ticket.manage_rules',
            'ticket.rule_publish',
            'ticket.update',
        ]);
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $catalog = app(MutateLegacyTicketRuleCatalog::class);
        $draft = $catalog->create($this->ruleAttributes((int) $defaults['type']->id));
        $draftPayload = [
            'name' => 'Protected draft',
            'description' => null,
            'definition' => ['schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION],
        ];
        $draft->forceFill([
            'draft_payload_json' => $draftPayload,
            'draft_checksum' => TicketRuleStableJson::checksum($draftPayload),
            'draft_updated_by' => $operator->id,
            'draft_updated_at' => now(),
        ])->save();

        $before = $this->catalogState($draft);
        $requestPayload = $this->requestPayload((int) $defaults['type']->id);
        $this->actingAs($operator)
            ->put(route('tech.admin.settings.tickets.rules.update', $draft), $requestPayload)
            ->assertRedirect()
            ->assertSessionHasErrors('rule');
        $this->actingAs($operator)
            ->post(route('tech.admin.settings.tickets.rules.toggle', $draft))
            ->assertRedirect()
            ->assertSessionHasErrors('rule');
        $this->actingAs($operator)
            ->delete(route('tech.admin.settings.tickets.rules.destroy', $draft))
            ->assertRedirect()
            ->assertSessionHasErrors('rule');
        $this->assertSame($before, $this->catalogState($draft));

        $this->assertLegacyRejected(fn () => $catalog->update($draft->fresh(), ['weight' => 99]));
        $this->assertLegacyRejected(fn () => $catalog->toggle($draft->fresh()));
        $this->assertLegacyRejected(fn () => $catalog->delete($draft->fresh()));
        $this->assertSame($before, $this->catalogState($draft));

        $beforeGovernedCreate = $this->catalogState();
        $this->assertLegacyRejected(fn () => $catalog->create(
            $this->ruleAttributes((int) $defaults['type']->id) + [
                'draft_payload_json' => $draftPayload,
            ],
        ));
        $this->assertSame($beforeGovernedCreate, $this->catalogState());
    }

    #[Test]
    public function schema_two_publications_never_cross_update_toggle_or_delete_legacy_boundaries(): void
    {
        $operator = $this->operator([
            'ticket.manage_rules',
            'ticket.rule_publish',
            'ticket.update',
        ]);
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $catalog = app(MutateLegacyTicketRuleCatalog::class);
        $rule = $catalog->create(array_replace(
            $this->ruleAttributes((int) $defaults['type']->id),
            ['is_active' => false],
        ));
        $definition = [
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'trigger_filters' => [],
            'conditions' => ['mode' => 'always', 'match' => 'ALL', 'groups' => []],
            'then_actions' => [],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 10],
        ];
        $checksum = TicketRuleStableJson::checksum($definition);
        $version = TicketRuleVersion::query()->create([
            'ticket_rule_id' => $rule->id,
            'version_number' => 1,
            'status' => TicketRuleVersion::STATUS_PUBLISHED,
            'definition_schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger_key' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'weight' => 10,
            'stop_processing' => false,
            'name' => 'Schema two protected rule',
            'description' => null,
            'definition_json' => $definition,
            'definition_checksum' => $checksum,
            'source_is_active' => false,
            'source_trigger' => TicketRule::TRIGGER_CREATE,
            'source_hit_count' => 0,
            'published_by' => $operator->id,
            'published_at' => now(),
            'provenance' => TicketRuleVersion::PROVENANCE_ADMIN_PUBLISH,
            'provenance_batch_uuid' => (string) Str::uuid(),
            'provenance_recorded_at' => now(),
        ]);
        DB::table('ticket_rules')->where('id', $rule->id)->update([
            'is_active' => false,
            'lifecycle_status' => TicketRule::LIFECYCLE_DISABLED,
            'published_version_id' => $version->id,
            'published_by' => $operator->id,
            'published_at' => now(),
            'definition_schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'definition_checksum' => $checksum,
            'compatibility_status' => TicketRule::COMPATIBILITY_ELIGIBLE,
        ]);
        $rule->refresh();

        $before = $this->catalogState($rule);
        $requestPayload = $this->requestPayload((int) $defaults['type']->id);
        $this->actingAs($operator)
            ->put(route('tech.admin.settings.tickets.rules.update', $rule), $requestPayload)
            ->assertRedirect()
            ->assertSessionHasErrors('rule');
        $this->actingAs($operator)
            ->post(route('tech.admin.settings.tickets.rules.toggle', $rule), [
                'published_version_id' => $version->id,
                'definition_checksum' => $checksum,
                'expected_enabled' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('rule');
        $this->actingAs($operator)
            ->delete(route('tech.admin.settings.tickets.rules.destroy', $rule))
            ->assertRedirect()
            ->assertSessionHasErrors('rule');
        $this->assertSame($before, $this->catalogState($rule));

        $this->assertLegacyRejected(fn () => $catalog->update($rule->fresh(), ['weight' => 99]));
        $this->assertLegacyRejected(fn () => $catalog->toggle($rule->fresh()));
        $this->assertLegacyRejected(fn () => $catalog->delete($rule->fresh()));
        $this->assertSame($before, $this->catalogState($rule));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function operator(array $permissions): User
    {
        $techRole = Role::findOrCreate('Tech', 'web');
        $adminRole = Role::findOrCreate('Admin', 'web');

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $adminRole->givePermissionTo($permissions);

        $operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $operator->assignRole([$techRole, $adminRole]);

        return $operator;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestPayload(int $ticketTypeId): array
    {
        return [
            'name' => 'Boundary request rule',
            'description' => 'Permission and schema boundary regression.',
            'weight' => 10,
            'is_active' => '1',
            'stop_processing' => '0',
            'conditions' => [[
                'field' => 'channel',
                'operator' => 'equals',
                'value' => 'email',
            ]],
            'actions' => [[
                'type' => 'set_ticket_type',
                'value' => (string) $ticketTypeId,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleAttributes(int $ticketTypeId): array
    {
        return [
            'name' => 'Boundary fixture '.Str::random(8),
            'description' => 'Legacy boundary fixture.',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => 10,
            'is_active' => true,
            'stop_processing' => false,
            'conditions_json' => [[
                'field' => 'channel',
                'operator' => 'equals',
                'value' => 'email',
            ]],
            'actions_json' => [[
                'type' => 'set_ticket_type',
                'value' => (string) $ticketTypeId,
            ]],
            'hit_count' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogState(?TicketRule $rule = null): array
    {
        return [
            'rule_count' => TicketRule::withTrashed()->count(),
            'rule' => $rule
                ? (array) DB::table('ticket_rules')->where('id', $rule->id)->first()
                : null,
            'fence' => (array) DB::table('ticket_rule_authority_fences')
                ->where('scope', TicketRuleAuthorityFence::SCOPE)
                ->first(),
        ];
    }

    private function assertAuthorizationRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The current operator authorization must be required.');
        } catch (AuthorizationException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    private function assertLegacyRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The legacy mutation boundary must reject this rule.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rule', $exception->errors());
        }
    }

    private function generation(): int
    {
        return TicketRuleAuthorityFence::query()
            ->findOrFail(TicketRuleAuthorityFence::SCOPE)
            ->catalog_generation;
    }
}
