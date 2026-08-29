<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Models\CustomFieldValue;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketCustomFieldSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Tech']);
        Role::firstOrCreate(['name' => 'Admin']);
        foreach (['ticket.view', 'ticket.create', 'ticket.update', 'ticket.assign'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        $this->tech->givePermissionTo(['ticket.view', 'ticket.create', 'ticket.update', 'ticket.assign']);

        config([
            'ticket_rules.capabilities.custom_fields.ui_write' => false,
            'ticket_rules.capabilities.custom_fields.api_write' => false,
            'ticket_rules.v2_enabled' => false,
        ]);

        app(EnsureTicketDefaults::class)->handle();
    }

    #[Test]
    public function write_gates_default_off_while_authorized_tech_and_api_reads_remain_available(): void
    {
        $definition = $this->definition('surface_code', 'Surface code');
        $ticket = app(StoreTicket::class)->handle([
            'subject' => 'Read-only Custom Field surface',
            'suppress_notifications' => true,
        ], $this->tech);
        $this->storeValue($definition, $ticket, ['value_text' => 'READ-ONLY-42']);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.create'))
            ->assertOk()
            ->assertDontSee('Surface code');

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Custom fields')
            ->assertSee('Surface code')
            ->assertSee('READ-ONLY-42');

        Sanctum::actingAs($this->tech, ['tickets.read']);
        $this->getJson(route('api.v1.tickets.show', $ticket))
            ->assertOk()
            ->assertJsonPath('data.custom_fields.surface_code', 'READ-ONLY-42');

        Sanctum::actingAs($this->tech, ['tickets.create']);
        $this->postJson(route('api.v1.tickets.store'), [
            'subject' => 'Disabled API Custom Field write',
            'custom_fields' => ['surface_code' => 'BLOCKED'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('custom_fields');

        $this->assertDatabaseMissing('tickets', ['subject' => 'Disabled API Custom Field write']);
        $this->assertDatabaseMissing('custom_field_values', ['value_text' => 'BLOCKED']);

        $this->actingAs($this->tech)
            ->from(route('tech.tickets.create'))
            ->post(route('tech.tickets.store'), [
                'subject' => 'Disabled UI Custom Field write',
                'custom_fields' => ['surface_code' => 'BLOCKED-UI'],
            ])
            ->assertRedirect(route('tech.tickets.create'))
            ->assertSessionHasErrors('custom_fields');

        $this->assertDatabaseMissing('tickets', ['subject' => 'Disabled UI Custom Field write']);
    }

    #[Test]
    public function enabled_tech_create_edit_and_show_surfaces_use_shared_typed_inputs_and_one_save_boundary(): void
    {
        config(['ticket_rules.capabilities.custom_fields.ui_write' => true]);

        $required = $this->definition('support_code', 'Support code', [
            'required' => true,
        ]);
        $enabled = $this->definition('monitoring_enabled', 'Monitoring enabled', [
            'field_type' => CustomFieldDefinition::TYPE_CHECKBOX,
        ]);
        $route = $this->definition('support_route', 'Support route', [
            'field_type' => CustomFieldDefinition::TYPE_SELECT,
            'options' => ['Hardware', 'Cloud'],
        ]);
        $watchers = $this->definition('watchers', 'Watchers', [
            'field_type' => CustomFieldDefinition::TYPE_MULTISELECT,
            'options' => ['Operations', 'Security'],
        ]);
        $this->definition('target_date', 'Target date', [
            'field_type' => CustomFieldDefinition::TYPE_DATE,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.create'))
            ->assertOk()
            ->assertSee('Support code')
            ->assertSee('name="custom_fields[support_code]"', false)
            ->assertSee('name="custom_fields[monitoring_enabled]"', false)
            ->assertSee('name="custom_fields[support_route]"', false)
            ->assertSee('name="custom_fields[watchers][]"', false)
            ->assertSee('type="date"', false);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.store'), [
                'subject' => 'Custom Field UI Ticket',
                'custom_fields' => [
                    'support_code' => 'SUP-100',
                    'monitoring_enabled' => '1',
                    'support_route' => 'Cloud',
                    'watchers' => ['Operations', 'Security'],
                    'target_date' => '2026-09-01',
                ],
            ])
            ->assertRedirect();

        $ticket = Ticket::query()->where('subject', 'Custom Field UI Ticket')->firstOrFail();

        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_definition_id' => $required->id,
            'model_type' => Ticket::class,
            'model_id' => $ticket->id,
            'value_text' => 'SUP-100',
        ]);
        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_definition_id' => $enabled->id,
            'model_id' => $ticket->id,
            'value_boolean' => true,
        ]);
        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_definition_id' => $route->id,
            'model_id' => $ticket->id,
            'value_text' => 'Cloud',
        ]);
        $this->assertSame(
            ['Operations', 'Security'],
            CustomFieldValue::query()
                ->where('custom_field_definition_id', $watchers->id)
                ->where('model_id', $ticket->id)
                ->firstOrFail()
                ->value_json,
        );

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('SUP-100')
            ->assertSee('Yes')
            ->assertSee('Operations, Security');

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.edit', $ticket))
            ->assertOk()
            ->assertSee('value="SUP-100"', false);

        $this->actingAs($this->tech)
            ->patch(route('tech.tickets.update', $ticket), [
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'queue_id' => $ticket->queue_id,
                'status_id' => $ticket->status_id,
                'priority_id' => $ticket->priority_id,
                'custom_fields' => [
                    'support_code' => 'SUP-101',
                ],
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_definition_id' => $required->id,
            'model_id' => $ticket->id,
            'value_text' => 'SUP-101',
        ]);
        $this->assertSame(2, $ticket->events()->where('type', 'custom_fields_changed')->count());
    }

    #[Test]
    public function enabled_api_create_update_validation_and_ability_boundaries_are_explicit(): void
    {
        config(['ticket_rules.capabilities.custom_fields.api_write' => true]);

        $required = $this->definition('integration_key', 'Integration key', [
            'required' => true,
        ]);
        $route = $this->definition('integration_route', 'Integration route', [
            'field_type' => CustomFieldDefinition::TYPE_SELECT,
            'options' => ['Primary', 'Secondary'],
        ]);
        $unique = $this->definition('external_reference', 'External reference', [
            'unique_per_model' => true,
        ]);

        Sanctum::actingAs($this->tech, ['tickets.read', 'tickets.create', 'tickets.update']);

        $response = $this->postJson(route('api.v1.tickets.store'), [
            'subject' => 'Custom Field API Ticket',
            'custom_fields' => [
                'integration_key' => 'API-100',
                'integration_route' => 'Primary',
                'external_reference' => 'EXT-ONE',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.custom_fields.integration_key', 'API-100')
            ->assertJsonPath('data.custom_fields.integration_route', 'Primary');

        $ticket = Ticket::query()->findOrFail($response->json('data.id'));

        $this->patchJson(route('api.v1.tickets.update', $ticket), [
            'custom_fields' => ['integration_key' => 'API-101'],
        ])
            ->assertOk()
            ->assertJsonPath('data.custom_fields.integration_key', 'API-101');

        $this->patchJson(route('api.v1.tickets.update', $ticket), [
            'custom_fields' => ['integration_route' => 'Unconfigured'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('custom_fields.integration_route');
        $this->postJson(route('api.v1.tickets.store'), [
            'subject' => 'Duplicate unique Custom Field',
            'custom_fields' => [
                'integration_key' => 'API-200',
                'integration_route' => 'Secondary',
                'external_reference' => 'EXT-ONE',
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('custom_fields.external_reference');

        $this->assertDatabaseMissing('tickets', ['subject' => 'Duplicate unique Custom Field']);

        $this->postJson(route('api.v1.tickets.store'), [
            'subject' => 'Missing required Custom Field',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('custom_fields.integration_key');

        $this->assertDatabaseMissing('tickets', ['subject' => 'Missing required Custom Field']);
        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_definition_id' => $required->id,
            'model_id' => $ticket->id,
            'value_text' => 'API-101',
        ]);
        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_definition_id' => $route->id,
            'model_id' => $ticket->id,
            'value_text' => 'Primary',
        ]);
        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_definition_id' => $unique->id,
            'model_id' => $ticket->id,
            'value_text' => 'EXT-ONE',
        ]);

        Sanctum::actingAs($this->tech, ['tickets.read']);
        $this->patchJson(route('api.v1.tickets.update', $ticket), [
            'custom_fields' => ['integration_key' => 'ABILITY-BLOCKED'],
        ])->assertForbidden();

        $this->assertDatabaseMissing('custom_field_values', ['value_text' => 'ABILITY-BLOCKED']);
    }

    #[Test]
    public function ticket_and_definition_permissions_prevent_disclosure_and_unauthorized_edits(): void
    {
        Permission::findOrCreate('ticket.custom_fields.view_sensitive', 'web');
        Permission::findOrCreate('ticket.custom_fields.edit_sensitive', 'web');
        config([
            'ticket_rules.capabilities.custom_fields.ui_write' => true,
            'ticket_rules.capabilities.custom_fields.api_write' => true,
        ]);

        $public = $this->definition('public_code', 'Public code');
        $adminOnly = $this->definition('admin_secret', 'Admin secret', [
            'admin_only' => true,
        ]);
        $permissionGuarded = $this->definition('guarded_secret', 'Guarded secret', [
            'view_permission' => 'ticket.custom_fields.view_sensitive',
        ]);
        $editGuarded = $this->definition('edit_guarded', 'Edit-protected field', [
            'edit_permission' => 'ticket.custom_fields.edit_sensitive',
        ]);
        $ticket = app(StoreTicket::class)->handle([
            'subject' => 'Custom Field permission Ticket',
            'suppress_notifications' => true,
        ], $this->tech);
        $this->storeValue($public, $ticket, ['value_text' => 'PUBLIC-1']);
        $this->storeValue($adminOnly, $ticket, ['value_text' => 'ADMIN-SECRET-1']);
        $this->storeValue($permissionGuarded, $ticket, ['value_text' => 'GUARDED-SECRET-1']);
        $this->storeValue($editGuarded, $ticket, ['value_text' => 'EDIT-SENSITIVE-1']);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('PUBLIC-1')
            ->assertSee('EDIT-SENSITIVE-1')
            ->assertDontSee('Admin secret')
            ->assertDontSee('ADMIN-SECRET-1')
            ->assertDontSee('Guarded secret')
            ->assertDontSee('GUARDED-SECRET-1');

        Sanctum::actingAs($this->tech, ['tickets.read']);
        $techPayload = $this->getJson(route('api.v1.tickets.show', $ticket))
            ->assertOk()
            ->json('data.custom_fields');

        $this->assertSame([
            'edit_guarded' => 'EDIT-SENSITIVE-1',
            'public_code' => 'PUBLIC-1',
        ], $techPayload);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.edit', $ticket))
            ->assertOk()
            ->assertDontSee('Edit-protected field');

        $this->actingAs($this->tech)
            ->from(route('tech.tickets.edit', $ticket))
            ->patch(route('tech.tickets.update', $ticket), [
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'queue_id' => $ticket->queue_id,
                'status_id' => $ticket->status_id,
                'priority_id' => $ticket->priority_id,
                'custom_fields' => ['edit_guarded' => 'UI-EDIT-BLOCKED'],
            ])
            ->assertRedirect(route('tech.tickets.edit', $ticket))
            ->assertSessionHasErrors('custom_fields.edit_guarded');

        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_definition_id' => $editGuarded->id,
            'model_id' => $ticket->id,
            'value_text' => 'EDIT-SENSITIVE-1',
        ]);

        Sanctum::actingAs($this->tech, ['tickets.update']);
        $this->patchJson(route('api.v1.tickets.update', $ticket), [
            'custom_fields' => ['edit_guarded' => 'API-EDIT-BLOCKED'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('custom_fields.edit_guarded');

        $this->assertDatabaseMissing('custom_field_values', ['value_text' => 'API-EDIT-BLOCKED']);

        $noTicketView = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($noTicketView, ['tickets.read']);
        $this->getJson(route('api.v1.tickets.show', $ticket))
            ->assertOk()
            ->assertJsonPath('data.custom_fields', []);

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['ticket.view', 'ticket.custom_fields.view_sensitive']);
        Sanctum::actingAs($admin, ['tickets.read']);

        $this->getJson(route('api.v1.tickets.show', $ticket))
            ->assertOk()
            ->assertJsonPath('data.custom_fields.public_code', 'PUBLIC-1')
            ->assertJsonPath('data.custom_fields.admin_secret', 'ADMIN-SECRET-1')
            ->assertJsonPath('data.custom_fields.guarded_secret', 'GUARDED-SECRET-1')
            ->assertJsonPath('data.custom_fields.edit_guarded', 'EDIT-SENSITIVE-1');
    }

    #[Test]
    public function ui_and_api_custom_field_reads_and_writes_fail_closed_for_mismatched_ticket_work_context(): void
    {
        config(['ticket_rules.capabilities.custom_fields.api_write' => true]);

        $definition = $this->definition('context_code', 'Context code');
        $ticket = app(StoreTicket::class)->handle([
            'subject' => 'Mismatched context Ticket',
            'suppress_notifications' => true,
        ], $this->tech);
        $client = Client::factory()->create();
        $clientContext = app(ResolveWorkContext::class)->fromClientId($client->id);
        $ticket->forceFill(['work_context_id' => $clientContext->id])->save();
        $this->storeValue($definition, $ticket, ['value_text' => 'MUST-NOT-READ']);

        $this->actingAs($this->tech)
            ->get(route('tech.tickets.show', $ticket))
            ->assertOk()
            ->assertDontSee('Context code')
            ->assertDontSee('MUST-NOT-READ');

        Sanctum::actingAs($this->tech, ['tickets.read']);
        $this->getJson(route('api.v1.tickets.show', $ticket))
            ->assertOk()
            ->assertJsonPath('data.custom_fields', []);

        Sanctum::actingAs($this->tech, ['tickets.update']);
        $this->patchJson(route('api.v1.tickets.update', $ticket), [
            'custom_fields' => ['context_code' => 'MUST-NOT-WRITE'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ticket');

        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_definition_id' => $definition->id,
            'model_id' => $ticket->id,
            'value_text' => 'MUST-NOT-READ',
        ]);
    }

    #[Test]
    public function customer_portal_never_discloses_ticket_custom_field_labels_or_values(): void
    {
        $definition = $this->definition('portal_hidden_value', 'Internal routing reference');
        [$client, $site, $contact, $portalUser] = $this->portalFixture('custom-field-portal@example.test');
        $clientUser = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'contact_id' => $contact->id,
            'email' => 'custom-field-portal@example.test',
        ]);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'contact_id' => $clientUser->id,
            'subject' => 'Portal Custom Field isolation',
            'portal_visible_at' => now(),
        ]);
        $this->storeValue($definition, $ticket, ['value_text' => 'NEVER-IN-PORTAL']);

        $this->actingAs($portalUser)
            ->get(route('customer-portal.tickets.show', $ticket))
            ->assertOk()
            ->assertDontSee('Internal routing reference')
            ->assertDontSee('NEVER-IN-PORTAL');

        $this->actingAs($portalUser)
            ->get(route('customer-portal.tickets.create'))
            ->assertOk()
            ->assertDontSee('Internal routing reference');
    }

    private function definition(string $key, string $label, array $attributes = []): CustomFieldDefinition
    {
        return CustomFieldDefinition::query()->create(array_merge([
            'model_type' => Ticket::class,
            'key' => $key,
            'label' => $label,
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

    private function storeValue(
        CustomFieldDefinition $definition,
        Ticket $ticket,
        array $value,
    ): CustomFieldValue {
        return CustomFieldValue::query()->create(array_merge([
            'custom_field_definition_id' => $definition->id,
            'model_type' => Ticket::class,
            'model_id' => $ticket->id,
        ], $value));
    }

    /**
     * @return array{0: Client, 1: ClientSite, 2: Contact, 3: User}
     */
    private function portalFixture(string $email): array
    {
        $client = Client::factory()->create(['name' => 'Portal Custom Field Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Main Office']);
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Portal Custom Field Contact',
        ]);
        ContactEmail::query()->create([
            'contact_id' => $contact->id,
            'label' => 'work',
            'email' => $email,
            'is_primary' => true,
            'is_verified' => true,
        ]);
        foreach ([$client, $site] as $related) {
            ContactRelation::query()->create([
                'contact_id' => $contact->id,
                'related_type' => $related->getMorphClass(),
                'related_id' => $related->id,
                'relation_type' => 'contact',
                'is_primary' => true,
            ]);
        }

        $user = User::factory()->create([
            'contact_id' => $contact->id,
            'name' => $contact->display_name,
            'email' => $email,
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
            'site_id' => $site->id,
            'role' => CustomerPortalMembership::ROLE_VIEWER,
            'status' => CustomerPortalMembership::STATUS_ACTIVE,
        ]);

        return [$client, $site, $contact, $user];
    }
}
