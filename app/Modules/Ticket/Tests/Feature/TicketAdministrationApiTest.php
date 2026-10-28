<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\TicketAssignmentRule;
use App\Modules\Ticket\Models\TicketAssignmentSetting;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketAdministrationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Role::findOrCreate('Tech', 'web');
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        foreach (['ticket.view', 'ticket.create', 'ticket.update', 'ticket.manage_settings', 'ticket.manage_rules'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->tech->givePermissionTo($permission);
        }
        app(EnsureTicketDefaults::class)->handle();
        Sanctum::actingAs($this->tech, ['tickets.catalogs.read', 'tickets.catalogs.manage', 'tickets.settings.read', 'tickets.settings.update', 'tickets.profile.read', 'tickets.profile.update', 'tickets.assignment.read', 'tickets.assignment.manage']);
    }

    public function test_all_four_catalogs_support_full_crud_default_selection_and_bounded_readback(): void
    {
        foreach (['queues', 'types', 'statuses', 'priorities'] as $catalog) {
            $data = ['name' => 'API '.$catalog, 'is_active' => true, 'sort_order' => 99];
            if ($catalog !== 'types') {
                $data['is_default'] = true;
            }
            if ($catalog === 'statuses') {
                $data['state'] = 'open';
            }
            if ($catalog === 'priorities') {
                $data['level'] = 99;
            }
            $id = $this->postJson(route('api.v1.ticket-'.$catalog.'.store'), $data)->assertCreated()->json('data.id');
            $model = match ($catalog) {
                'queues' => TicketQueue::class, 'types' => TicketType::class, 'statuses' => TicketStatus::class, default => TicketPriority::class
            };
            if ($catalog !== 'types') {
                $this->assertSame($id, $model::query()->where('is_default', true)->sole()->id);
            }
            $this->getJson(route('api.v1.ticket-'.$catalog.'.show', $id))->assertOk()->assertJsonPath('data.name', 'API '.$catalog)->assertJsonMissingPath('data.settings');
            $this->getJson(route('api.v1.ticket-'.$catalog.'.index', ['per_page' => 1]))->assertOk()->assertJsonPath('meta.per_page', 1);
            $this->getJson(route('api.v1.ticket-'.$catalog.'.index', ['per_page' => 101]))->assertUnprocessable();
            $data['name'] = 'Edited '.$catalog;
            $this->putJson(route('api.v1.ticket-'.$catalog.'.update', $id), $data)->assertOk()->assertJsonPath('data.name', 'Edited '.$catalog);
            $this->deleteJson(route('api.v1.ticket-'.$catalog.'.destroy', $id))->assertOk()->assertJsonPath('data.deleted', true);
            $this->getJson(route('api.v1.ticket-'.$catalog.'.show', $id))->assertNotFound();
        }
        $this->assertGreaterThanOrEqual(12, Activity::where('log_name', 'domain_api')->count());
    }

    public function test_catalog_deletion_retains_existing_used_and_system_protection(): void
    {
        $ticket = app(StoreTicket::class)->handle(['subject' => 'Protected catalog', 'client_id' => null], $this->tech);
        $this->deleteJson(route('api.v1.ticket-queues.destroy', $ticket->queue_id))->assertUnprocessable();
        $this->deleteJson(route('api.v1.ticket-statuses.destroy', $ticket->status_id))->assertUnprocessable();
        $this->deleteJson(route('api.v1.ticket-priorities.destroy', $ticket->priority_id))->assertUnprocessable();
        $type = TicketType::create(['name' => 'System type', 'slug' => 'system-api', 'is_system' => true, 'is_deletable' => false, 'is_active' => true]);
        $this->putJson(route('api.v1.ticket-types.update', $type), ['name' => 'System type', 'is_active' => true, 'is_deletable' => true])->assertOk()->assertJsonPath('data.is_deletable', false);
        $this->deleteJson(route('api.v1.ticket-types.destroy', $type))->assertUnprocessable();
    }

    public function test_settings_keep_fixed_policies_and_default_email_scopes_without_serializing_secrets(): void
    {
        $old = $this->email('old@example.test', ['tickets', 'sales']);
        $new = $this->email('new@example.test', ['alerts']);
        $this->putJson(route('api.v1.ticket-settings.email'), ['email_account_id' => $new->id])->assertOk()->assertJsonPath('data.default_email_account_id', $new->id);
        $this->assertSame(['sales'], $old->refresh()->defaults_for);
        $this->assertEqualsCanonicalizing(['alerts', 'tickets'], $new->refresh()->defaults_for);
        $this->getJson(route('api.v1.ticket-settings.context', ['catalog' => 'email-accounts']))->assertOk()->assertJsonMissingPath('data.0.smtp_secret')->assertJsonMissingPath('data.0.imap_secret');
        $this->putJson(route('api.v1.ticket-settings.solution'), ['allow_internal_solution_notes' => false])->assertOk()->assertJsonPath('data.solution_policy.allow_internal_solution_notes', false);
        $this->putJson(route('api.v1.ticket-settings.portal'), ['default_customer_visibility' => 'unpublished'])->assertOk()->assertJsonPath('data.portal_policy.default_customer_visibility', 'unpublished');
        $this->putJson(route('api.v1.ticket-settings.quote'), ['quote_required_cost_threshold' => 1234.50])->assertOk()->assertJsonPath('data.quote_cost_policy.quote_required_cost_threshold', 1234.5);
        $this->putJson(route('api.v1.ticket-settings.merge'), ['auto_merge_enabled' => false, 'ai_merge_enabled' => true, 'ai_similarity_threshold' => 95])->assertOk()->assertJsonPath('data.merge_settings.ai_similarity_threshold', 95);
        $this->getJson(route('api.v1.ticket-settings.show'))->assertOk()->assertJsonPath('data.merge_settings.auto_merge_enabled', false);
        $this->getJson(route('api.v1.ticket-settings.history'))->assertOk()->assertJsonMissingPath('data.0.properties.before')->assertDontSee('not-a-real-credential', false);
    }

    public function test_get_own_profile_is_read_only_and_own_update_cannot_choose_another_user(): void
    {
        Sanctum::actingAs($this->tech, ['tickets.profile.read', 'tickets.profile.update']);
        $this->getJson(route('api.v1.ticket-assignment.own'))->assertOk()->assertJsonPath('data.id', null)->assertJsonPath('data.max_open_tickets', 10);
        $this->assertSame(0, TicketAssignmentSetting::count());
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->putJson(route('api.v1.ticket-assignment.own.update'), ['user_id' => $other->id, 'is_assignable' => true])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->putJson(route('api.v1.ticket-assignment.own.update'), ['is_assignable' => true, 'max_open_tickets' => 12, 'notes' => 'Private assignment note'])
            ->assertOk()->assertJsonPath('data.user_id', $this->tech->id)->assertJsonPath('data.max_open_tickets', 12);
        $this->assertSame(0, TicketAssignmentSetting::where('user_id', $other->id)->count());
        $this->getJson(route('api.v1.ticket-assignment.own.history'))->assertOk()->assertJsonPath('meta.total', 1)->assertDontSee('Private assignment note', false);
        $this->getJson(route('api.v1.ticket-assignment.own.context', ['catalog' => 'users']))->assertNotFound();
    }

    public function test_admin_profile_creation_and_update_have_exact_scope_and_validation(): void
    {
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $id = $this->postJson(route('api.v1.ticket-assignment.profiles.store'), ['user_id' => $other->id])->assertCreated()->assertJsonPath('data.user_id', $other->id)->json('data.id');
        $this->putJson(route('api.v1.ticket-assignment.profiles.update', $id), ['is_assignable' => false, 'max_open_tickets' => 3])->assertOk()->assertJsonPath('data.is_assignable', false);
        $this->getJson(route('api.v1.ticket-assignment.profiles.index'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonMissingPath('data.0.user.password');
        $this->getJson(route('api.v1.ticket-assignment.profiles.show', $id))->assertOk()->assertJsonPath('data.max_open_tickets', 3);
        $this->putJson(route('api.v1.ticket-assignment.profiles.update', $id), ['max_open_tickets' => 0])->assertUnprocessable();
        Sanctum::actingAs($this->tech, ['tickets.profile.update']);
        $this->putJson(route('api.v1.ticket-assignment.profiles.update', $id), ['max_open_tickets' => 9])->assertForbidden();
    }

    public function test_assignment_rule_create_is_idempotent_conflict_checked_and_key_stays_reserved_after_delete(): void
    {
        $payload = ['idempotency_key' => 'create-rule-once', 'name' => 'API assignment rule', 'weight' => 1, 'is_active' => true,
            'conditions' => [['field' => 'channel', 'operator' => 'equals', 'value' => 'api']], 'action_value' => $this->tech->id];
        $url = route('api.v1.ticket-assignment.rules.store');
        $id = $this->postJson($url, $payload)->assertCreated()->json('data.id');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, TicketAssignmentRule::count());
        $this->assertSame(1, Activity::where('event', 'ticket.assignment.rule.created')->count());
        $changed = $payload;
        $changed['name'] = 'A different request';
        $this->postJson($url, $changed)->assertStatus(409);
        $this->getJson(route('api.v1.ticket-assignment.rules.show', $id))->assertOk();
        $this->deleteJson(route('api.v1.ticket-assignment.rules.destroy', $id))->assertOk();
        $this->postJson($url, $payload)->assertStatus(409);
    }

    public function test_configuration_audit_failure_rolls_back_the_policy_change_and_does_not_store_private_values(): void
    {
        $before = $this->getJson(route('api.v1.ticket-settings.show'))->assertOk()->json('data.solution_policy.allow_internal_solution_notes');
        activity()->withoutLogs(function () use ($before): void {
            $this->putJson(route('api.v1.ticket-settings.solution'), ['allow_internal_solution_notes' => ! $before])->assertUnprocessable()->assertJsonValidationErrors('audit');
        });
        $this->getJson(route('api.v1.ticket-settings.show'))->assertOk()->assertJsonPath('data.solution_policy.allow_internal_solution_notes', $before);
        $this->assertSame(0, Activity::where('log_name', 'domain_api')->count());
    }

    public function test_all_administrative_reference_catalogs_are_bounded_and_safe(): void
    {
        foreach (['email-accounts', 'users', 'technicians-without-profiles', 'clients', 'contacts', 'categories', 'tags', 'queues', 'statuses', 'priorities', 'types', 'slas'] as $catalog) {
            $this->getJson(route('api.v1.ticket-settings.context', ['catalog' => $catalog, 'per_page' => 1]))->assertOk()->assertJsonPath('meta.per_page', 1)->assertJsonMissingPath('data.0.password');
        }
        $this->getJson(route('api.v1.ticket-settings.context', ['catalog' => 'arbitrary-setting']))->assertNotFound();
    }

    public function test_token_scopes_and_domain_rights_are_both_required_for_admin_writes(): void
    {
        Sanctum::actingAs($this->tech, ['tickets.settings.read', 'tickets.catalogs.read']);
        $this->putJson(route('api.v1.ticket-settings.solution'), ['allow_internal_solution_notes' => false])->assertForbidden();
        $this->postJson(route('api.v1.ticket-queues.store'), ['name' => 'Denied'])->assertForbidden();
        Sanctum::actingAs($this->tech, ['tickets.assignment.manage']);
        $this->tech->revokePermissionTo('ticket.manage_rules');
        $this->postJson(route('api.v1.ticket-assignment.rules.store'), [])->assertForbidden();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($other, ['*']);
        $this->getJson(route('api.v1.ticket-settings.show'))->assertForbidden();
        $this->postJson(route('api.v1.ticket-queues.store'), ['name' => 'Denied'])->assertForbidden();
    }

    private function email(string $address, array $defaults): EmailAccount
    {
        return EmailAccount::create(['address' => $address, 'description' => 'API fixture', 'from_name' => 'Support', 'is_active' => true,
            'is_global_default' => false, 'defaults_for' => $defaults, 'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_username' => $address,
            'imap_secret' => encrypt('not-a-real-credential'), 'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'smtp_username' => $address,
            'smtp_secret' => encrypt('not-a-real-credential'), 'smtp_auth_type' => 'password']);
    }
}
