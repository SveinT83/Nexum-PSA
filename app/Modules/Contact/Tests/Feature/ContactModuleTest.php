<?php

namespace App\Modules\Contact\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Contact\Controllers\Admin\ContactSettingsController;
use App\Modules\Contact\Controllers\Tech\ContactController;
use App\Modules\Contact\Livewire\Tech\ContactForm;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Support\ContactSettings;
use App\Modules\Signal\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ContactModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $techUser;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);

        $this->techUser = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->techUser->assignRole('Tech');
    }

    #[Test]
    public function contact_routes_are_owned_by_contact_module(): void
    {
        $this->assertSame(ContactController::class.'@index', Route::getRoutes()->getByName('tech.contacts.index')->getActionName());
        $this->assertSame(ContactController::class.'@clearContext', Route::getRoutes()->getByName('tech.contacts.context.clear')->getActionName());
        $this->assertSame(ContactController::class.'@create', Route::getRoutes()->getByName('tech.contacts.create')->getActionName());
        $this->assertSame(ContactController::class.'@store', Route::getRoutes()->getByName('tech.contacts.store')->getActionName());
        $this->assertSame(ContactController::class.'@edit', Route::getRoutes()->getByName('tech.contacts.edit')->getActionName());
        $this->assertSame(ContactController::class.'@show', Route::getRoutes()->getByName('tech.contacts.show')->getActionName());
        $this->assertSame(ContactSettingsController::class.'@edit', Route::getRoutes()->getByName('tech.admin.settings.contacts')->getActionName());
    }

    #[Test]
    public function admin_can_manage_contact_settings(): void
    {
        $this->actingAs($this->techUser)
            ->get(route('tech.admin.settings.contacts'))
            ->assertOk()
            ->assertViewIs('contact::Admin.Settings.edit')
            ->assertSee('Contact Settings')
            ->assertSee('Contact Defaults')
            ->assertSee('Relation Types');

        $this->actingAs($this->techUser)
            ->put(route('tech.admin.settings.contacts.update'), [
                'default_contact_type' => 'shared_mailbox',
                'default_status' => 'inactive',
                'enabled_relation_types' => ['contact', 'billing_contact'],
                'default_relation_type' => 'billing_contact',
            ])
            ->assertRedirect(route('tech.admin.settings.contacts'))
            ->assertSessionHas('success');

        $setting = CommonSetting::query()
            ->where('type', 'contact')
            ->where('name', 'defaults')
            ->firstOrFail();

        $payload = json_decode($setting->json, true);

        $this->assertSame('shared_mailbox', $payload['default_contact_type']);
        $this->assertSame('inactive', $payload['default_status']);
        $this->assertSame(['contact', 'billing_contact'], $payload['enabled_relation_types']);
        $this->assertSame('billing_contact', $payload['default_relation_type']);
    }

    #[Test]
    public function contact_create_uses_configured_defaults_and_relation_options(): void
    {
        app(ContactSettings::class)->update([
            'default_contact_type' => 'shared_mailbox',
            'default_status' => 'inactive',
            'enabled_relation_types' => ['contact', 'billing_contact'],
            'default_relation_type' => 'billing_contact',
        ]);

        $client = Client::factory()->create(['name' => 'Settings Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Settings Site']);

        Livewire::actingAs($this->techUser)
            ->test(ContactForm::class, ['activeClientId' => $client->id, 'activeSiteId' => $site->id])
            ->assertSet('relation_type', 'billing_contact')
            ->assertSee('Billing contact')
            ->assertDontSee('Technical contact')
            ->set('display_name', 'Billing Shared Mailbox')
            ->set('email', 'billing-shared@example.test')
            ->call('save');

        $contact = Contact::query()->where('display_name', 'Billing Shared Mailbox')->firstOrFail();

        $this->assertSame('shared_mailbox', $contact->type);
        $this->assertSame('inactive', $contact->status);
        $this->assertDatabaseHas('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $client->getMorphClass(),
            'related_id' => $client->id,
            'relation_type' => 'billing_contact',
        ]);
    }

    #[Test]
    public function tech_user_can_open_contact_index_from_clients_menu(): void
    {
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Ada Contact',
            'job_title' => 'Technical Contact',
        ]);
        $contact->emails()->create([
            'label' => 'work',
            'email' => 'ada@example.test',
            'is_primary' => true,
        ]);
        $contact->phones()->create([
            'label' => 'mobile',
            'phone' => '+4712345678',
            'is_primary' => true,
        ]);

        $this->actingAs($this->techUser)
            ->get(route('tech.contacts.index'))
            ->assertOk()
            ->assertViewIs('contact::Tech.index')
            ->assertSee('Clients</a>', false)
            ->assertSee('Contacts</a>', false)
            ->assertDontSee('Client Users</a>', false)
            ->assertSee(route('tech.contacts.create'), false)
            ->assertSee('data-href="'.route('tech.contacts.show', $contact).'"', false)
            ->assertSee('Ada Contact')
            ->assertSee('ada@example.test')
            ->assertSee('+4712345678');
    }

    #[Test]
    public function authenticated_api_user_can_list_contacts_with_contact_read_scope(): void
    {
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'API Contact',
            'job_title' => 'API Tester',
        ]);
        $contact->emails()->create([
            'label' => 'work',
            'email' => 'api.contact@example.test',
            'is_primary' => true,
        ]);
        $contact->phones()->create([
            'label' => 'mobile',
            'phone' => '+4711223344',
            'is_primary' => true,
        ]);

        Sanctum::actingAs($this->techUser, ['contacts.read']);

        $this->getJson(route('api.v1.contacts.index'))
            ->assertOk()
            ->assertJsonPath('data.0.display_name', 'API Contact')
            ->assertJsonPath('data.0.primary_email', 'api.contact@example.test')
            ->assertJsonPath('data.0.primary_phone', '+4711223344');

        $this->getJson(route('api.v1.contacts.show', $contact))
            ->assertOk()
            ->assertJsonPath('data.display_name', 'API Contact')
            ->assertJsonPath('data.emails.0.email', 'api.contact@example.test');
    }

    #[Test]
    public function asset_read_api_token_cannot_read_contacts(): void
    {
        Sanctum::actingAs($this->techUser, ['assets.read']);

        $this->getJson(route('api.v1.contacts.index'))
            ->assertForbidden();
    }

    #[Test]
    public function authenticated_api_user_can_find_contact_by_exact_email_or_phone(): void
    {
        $match = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Lookup Contact',
        ]);
        $match->emails()->create([
            'label' => 'work',
            'email' => 'lookup@example.test',
            'is_primary' => true,
        ]);
        $match->phones()->create([
            'label' => 'mobile',
            'phone' => '+47 99 88 77 66',
            'is_primary' => true,
        ]);

        Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Other Lookup Contact',
        ])->emails()->create([
            'label' => 'work',
            'email' => 'other.lookup@example.test',
            'is_primary' => true,
        ]);

        Sanctum::actingAs($this->techUser, ['contacts.read']);

        $this->getJson(route('api.v1.contacts.index', ['email' => 'lookup@example.test']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);

        $this->getJson(route('api.v1.contacts.index', ['phone' => '004799887766']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    #[Test]
    public function api_user_can_upsert_contact_and_link_client_context(): void
    {
        $client = Client::factory()->create(['name' => 'API Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'API Default Site', 'is_default' => true]);

        Sanctum::actingAs($this->techUser, ['contacts.create', 'contacts.update']);

        $this->postJson(route('api.v1.contacts.store'), [
            'display_name' => 'N8N Contact',
            'organization_name' => 'API Client',
            'job_title' => 'Operations',
            'email' => 'n8n.contact@example.test',
            'phone' => '+47 11 22 33 44',
            'do_not_email' => false,
            'marketing_consent' => true,
            'client_id' => $client->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'N8N Contact')
            ->assertJsonPath('data.primary_email', 'n8n.contact@example.test')
            ->assertJsonPath('data.do_not_email', false)
            ->assertJsonPath('data.marketing_consent', true)
            ->assertJsonPath('meta.created', true);

        $contact = Contact::query()->where('display_name', 'N8N Contact')->firstOrFail();

        $this->assertDatabaseHas('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $client->getMorphClass(),
            'related_id' => $client->id,
            'relation_type' => 'contact',
        ]);
        $this->assertDatabaseHas('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $site->getMorphClass(),
            'related_id' => $site->id,
            'relation_type' => 'contact',
        ]);
        $this->assertDatabaseHas('client_users', [
            'contact_id' => $contact->id,
            'client_site_id' => $site->id,
            'email' => 'n8n.contact@example.test',
        ]);

        $this->postJson(route('api.v1.contacts.store'), [
            'display_name' => 'N8N Contact Updated',
            'organization_name' => 'API Client',
            'job_title' => 'Service Desk',
            'email' => 'n8n.contact@example.test',
            'phone' => '+47 22 33 44 55',
            'do_not_email' => true,
            'marketing_consent' => false,
            'client_id' => $client->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $contact->id)
            ->assertJsonPath('data.display_name', 'N8N Contact Updated')
            ->assertJsonPath('data.primary_phone', '+47 22 33 44 55')
            ->assertJsonPath('data.do_not_email', true)
            ->assertJsonPath('data.marketing_consent', false)
            ->assertJsonPath('meta.upserted', true);

        $this->assertSame(1, Contact::query()->whereHas('emails', fn ($query) => $query->where('email', 'n8n.contact@example.test'))->count());
        $this->assertTrue($contact->fresh()->do_not_email);
        $this->assertFalse($contact->fresh()->marketing_consent);
    }

    #[Test]
    public function contact_read_api_token_cannot_create_contacts(): void
    {
        Sanctum::actingAs($this->techUser, ['contacts.read']);

        $this->postJson(route('api.v1.contacts.store'), [
            'display_name' => 'Blocked API Contact',
            'email' => 'blocked@example.test',
        ])->assertForbidden();
    }

    #[Test]
    public function api_user_can_update_contact_and_change_client_context(): void
    {
        $oldClient = Client::factory()->create(['name' => 'Old Client']);
        $oldSite = ClientSite::factory()->create(['client_id' => $oldClient->id, 'name' => 'Old Site']);
        $newClient = Client::factory()->create(['name' => 'New Client']);
        $newSite = ClientSite::factory()->create(['client_id' => $newClient->id, 'name' => 'New Site']);

        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Patch Contact',
        ]);
        $contact->emails()->create([
            'label' => 'work',
            'email' => 'patch@example.test',
            'is_primary' => true,
        ]);
        $contact->relations()->create([
            'related_type' => $oldClient->getMorphClass(),
            'related_id' => $oldClient->id,
            'relation_type' => 'contact',
            'is_primary' => true,
        ]);
        $contact->relations()->create([
            'related_type' => $oldSite->getMorphClass(),
            'related_id' => $oldSite->id,
            'relation_type' => 'contact',
            'is_primary' => true,
        ]);

        Sanctum::actingAs($this->techUser, ['contacts.update']);

        $this->patchJson(route('api.v1.contacts.update', $contact), [
            'display_name' => 'Patch Contact Updated',
            'client_id' => $newClient->id,
            'site_id' => $newSite->id,
            'relation_type' => 'technical_contact',
        ])
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Patch Contact Updated');

        $this->assertDatabaseMissing('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $oldClient->getMorphClass(),
            'related_id' => $oldClient->id,
        ]);
        $this->assertDatabaseHas('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $newClient->getMorphClass(),
            'related_id' => $newClient->id,
            'relation_type' => 'technical_contact',
        ]);
        $this->assertDatabaseHas('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $newSite->getMorphClass(),
            'related_id' => $newSite->id,
            'relation_type' => 'technical_contact',
        ]);
    }

    #[Test]
    public function api_user_can_inspect_client_contacts_by_client_number(): void
    {
        $client = Client::factory()->create(['name' => 'Numbered Client', 'client_number' => '10186']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Numbered Site', 'is_default' => true]);
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Ownership Contact',
        ]);
        $contact->emails()->create([
            'label' => 'work',
            'email' => 'ownership@example.test',
            'is_primary' => true,
        ]);
        $contact->relations()->create([
            'related_type' => $client->getMorphClass(),
            'related_id' => $client->id,
            'relation_type' => 'contact',
            'is_primary' => true,
        ]);
        $contact->relations()->create([
            'related_type' => $site->getMorphClass(),
            'related_id' => $site->id,
            'relation_type' => 'site_contact',
            'is_primary' => true,
        ]);
        ClientUser::factory()->create([
            'contact_id' => $contact->id,
            'client_site_id' => $site->id,
            'name' => 'Ownership Contact',
            'email' => 'ownership@example.test',
        ]);
        ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Legacy Only',
            'email' => 'legacy-only@example.test',
        ]);

        Sanctum::actingAs($this->techUser, ['contacts.read']);

        $this->getJson(route('api.v1.clients.contacts.index', ['client' => '10186']))
            ->assertOk()
            ->assertJsonPath('client.client_number', '10186')
            ->assertJsonPath('summary.contacts', 1)
            ->assertJsonPath('summary.legacy_client_users', 2)
            ->assertJsonPath('summary.legacy_without_contact', 1)
            ->assertJsonPath('contacts.0.display_name', 'Ownership Contact')
            ->assertJsonPath('contacts.0.legacy_client_users.0.client.client_number', '10186');
    }

    #[Test]
    public function contact_ownership_move_dry_run_does_not_mutate_data(): void
    {
        [$oldClient, $oldSite, $newClient, $newSite, $contact] = $this->ownershipMoveFixture();

        Sanctum::actingAs($this->techUser, ['contacts.ownership_manage']);

        $this->postJson(route('api.v1.contacts.move', $contact), [
            'target_client_number' => $newClient->client_number,
            'dry_run' => true,
            'reason' => 'Preview cleanup.',
        ])
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('changed', false)
            ->assertJsonPath('plan.status', 'would_move');

        $this->assertDatabaseHas('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $oldClient->getMorphClass(),
            'related_id' => $oldClient->id,
        ]);
        $this->assertDatabaseMissing('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $newClient->getMorphClass(),
            'related_id' => $newClient->id,
        ]);
        $this->assertDatabaseHas('client_users', [
            'contact_id' => $contact->id,
            'client_site_id' => $oldSite->id,
        ]);
        $this->assertDatabaseMissing('client_users', [
            'contact_id' => $contact->id,
            'client_site_id' => $newSite->id,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'contact_ownership',
            'event' => 'contact_ownership.move',
        ]);
    }

    #[Test]
    public function api_user_can_move_contact_ownership_by_client_number(): void
    {
        [$oldClient, $oldSite, $newClient, $newSite, $contact] = $this->ownershipMoveFixture();

        Sanctum::actingAs($this->techUser, ['contacts.ownership_manage']);

        $this->postJson(route('api.v1.contacts.move', $contact), [
            'target_client_number' => $newClient->client_number,
            'reason' => 'Move to correct client.',
        ])
            ->assertOk()
            ->assertJsonPath('dry_run', false)
            ->assertJsonPath('changed', true)
            ->assertJsonPath('plan.status', 'no_change')
            ->assertJsonPath('contact.legacy_client_users.0.site.id', $newSite->id);

        $this->assertDatabaseMissing('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $oldClient->getMorphClass(),
            'related_id' => $oldClient->id,
        ]);
        $this->assertDatabaseHas('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $newClient->getMorphClass(),
            'related_id' => $newClient->id,
            'relation_type' => 'technical_contact',
        ]);
        $this->assertDatabaseHas('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $newSite->getMorphClass(),
            'related_id' => $newSite->id,
            'relation_type' => 'technical_contact',
        ]);
        $this->assertDatabaseHas('client_users', [
            'contact_id' => $contact->id,
            'client_site_id' => $newSite->id,
            'email' => 'move@example.test',
        ]);
        $this->assertDatabaseMissing('client_users', [
            'contact_id' => $contact->id,
            'client_site_id' => $oldSite->id,
        ]);
    }

    #[Test]
    public function bulk_fix_dry_run_reports_missing_no_change_and_move_candidates(): void
    {
        $targetClient = Client::factory()->create(['name' => 'Target Client', 'client_number' => '20001']);
        $targetSite = ClientSite::factory()->create(['client_id' => $targetClient->id, 'name' => 'Target Site', 'is_default' => true]);
        $oldClient = Client::factory()->create(['name' => 'Old Client', 'client_number' => '20002']);
        $oldSite = ClientSite::factory()->create(['client_id' => $oldClient->id, 'name' => 'Old Site', 'is_default' => true]);

        $alreadyCorrect = $this->contactWithOwnership('Already Correct', 'correct@example.test', $targetClient, $targetSite);
        $moveCandidate = $this->contactWithOwnership('Move Candidate', 'candidate@example.test', $oldClient, $oldSite);

        Sanctum::actingAs($this->techUser, ['contacts.ownership_manage']);

        $this->postJson(route('api.v1.clients.contacts.bulk-fix', ['client' => $targetClient->client_number]), [
            'contact_ids' => [$alreadyCorrect->id, $moveCandidate->id, 999999],
            'dry_run' => true,
            'reason' => 'Preview production cleanup.',
        ])
            ->assertOk()
            ->assertJsonPath('summary.total', 3)
            ->assertJsonPath('summary.changed', 0)
            ->assertJsonPath('summary.missing_contacts', 1)
            ->assertJsonPath('summary.no_change', 1)
            ->assertJsonPath('results.0.status', 'no_change')
            ->assertJsonPath('results.1.status', 'would_move')
            ->assertJsonPath('results.2.status', 'missing_contact');
    }

    #[Test]
    public function api_user_can_detach_contact_from_client_without_deleting_contact(): void
    {
        $client = Client::factory()->create(['name' => 'Detach Client', 'client_number' => '30001']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Detach Site', 'is_default' => true]);
        $contact = $this->contactWithOwnership('Detach Contact', 'detach@example.test', $client, $site);
        $legacyClientUserId = ClientUser::query()
            ->where('contact_id', $contact->id)
            ->where('client_site_id', $site->id)
            ->value('id');
        $this->assertNotNull($legacyClientUserId);

        Sanctum::actingAs($this->techUser, ['contacts.ownership_manage']);

        $this->deleteJson(route('api.v1.clients.contacts.detach', ['client' => $client->client_number, 'contact' => $contact]), [
            'reason' => 'Detach from wrong client.',
        ])
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('plan.status', 'detached')
            ->assertJsonPath('plan.delete_legacy_client_user_ids.0', $legacyClientUserId);

        $this->assertDatabaseMissing('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $client->getMorphClass(),
            'related_id' => $client->id,
        ]);
        $this->assertDatabaseMissing('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $site->getMorphClass(),
            'related_id' => $site->id,
        ]);
        $this->assertDatabaseMissing('client_users', [
            'client_site_id' => $site->id,
            'email' => 'detach@example.test',
        ]);
        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function api_user_can_cleanup_legacy_client_users_without_contacts(): void
    {
        $client = Client::factory()->create(['name' => 'Legacy Cleanup Client', 'client_number' => '30002']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Legacy Cleanup Site', 'is_default' => true]);
        $otherClient = Client::factory()->create(['name' => 'Other Legacy Client', 'client_number' => '30003']);
        $otherSite = ClientSite::factory()->create(['client_id' => $otherClient->id, 'name' => 'Other Legacy Site', 'is_default' => true]);

        $orphanOne = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'contact_id' => null,
            'name' => 'Imported Wrong One',
            'email' => 'wrong-one@example.test',
        ]);
        $orphanTwo = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'contact_id' => null,
            'name' => 'Imported Wrong Two',
            'email' => 'wrong-two@example.test',
        ]);
        $linkedContact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Linked Contact',
        ]);
        $linkedRow = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'contact_id' => $linkedContact->id,
            'name' => 'Linked Contact',
            'email' => 'linked@example.test',
        ]);
        $otherRow = ClientUser::factory()->create([
            'client_site_id' => $otherSite->id,
            'contact_id' => null,
            'name' => 'Other Client User',
            'email' => 'other@example.test',
        ]);

        Sanctum::actingAs($this->techUser, ['contacts.ownership_manage']);

        $payload = [
            'client_user_ids' => [$orphanOne->id, $orphanTwo->id, $linkedRow->id, $otherRow->id, 999999],
            'dry_run' => true,
            'reason' => 'Preview legacy cleanup.',
        ];

        $this->postJson(route('api.v1.clients.contacts.legacy-orphans.cleanup', ['client' => $client->client_number]), $payload)
            ->assertOk()
            ->assertJsonPath('summary.total', 5)
            ->assertJsonPath('summary.eligible', 2)
            ->assertJsonPath('summary.changed', 0)
            ->assertJsonPath('summary.skipped', 3)
            ->assertJsonPath('results.0.status', 'would_delete')
            ->assertJsonPath('results.1.status', 'would_delete')
            ->assertJsonPath('results.2.status', 'linked_contact')
            ->assertJsonPath('results.3.status', 'wrong_client')
            ->assertJsonPath('results.4.status', 'missing_client_user');

        $this->assertDatabaseHas('client_users', ['id' => $orphanOne->id]);
        $this->assertDatabaseHas('client_users', ['id' => $orphanTwo->id]);

        $payload['dry_run'] = false;
        $payload['reason'] = 'Delete N8N-imported legacy rows.';

        $this->postJson(route('api.v1.clients.contacts.legacy-orphans.cleanup', ['client' => $client->client_number]), $payload)
            ->assertOk()
            ->assertJsonPath('summary.total', 5)
            ->assertJsonPath('summary.eligible', 2)
            ->assertJsonPath('summary.changed', 2)
            ->assertJsonPath('summary.skipped', 3)
            ->assertJsonPath('results.0.status', 'deleted')
            ->assertJsonPath('results.1.status', 'deleted');

        $this->assertDatabaseMissing('client_users', ['id' => $orphanOne->id]);
        $this->assertDatabaseMissing('client_users', ['id' => $orphanTwo->id]);
        $this->assertDatabaseHas('client_users', ['id' => $linkedRow->id]);
        $this->assertDatabaseHas('client_users', ['id' => $otherRow->id]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'contact_ownership',
            'event' => 'contact_ownership.legacy_orphan_cleanup',
        ]);
    }

    #[Test]
    public function contact_update_scope_cannot_move_contact_ownership(): void
    {
        $targetClient = Client::factory()->create(['client_number' => '40001']);
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Blocked Move Contact',
        ]);

        Sanctum::actingAs($this->techUser, ['contacts.update']);

        $this->postJson(route('api.v1.contacts.move', $contact), [
            'target_client_number' => $targetClient->client_number,
        ])->assertForbidden();
    }

    #[Test]
    public function contact_index_can_search_email_and_show_contact_detail(): void
    {
        $match = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Search Match',
        ]);
        $match->emails()->create([
            'label' => 'work',
            'email' => 'match@example.test',
            'is_primary' => true,
        ]);
        Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Other Contact',
        ]);

        $this->actingAs($this->techUser)
            ->get(route('tech.contacts.index', ['q' => 'match@example.test']))
            ->assertOk()
            ->assertSee('Search Match')
            ->assertDontSee('Other Contact');

        $this->actingAs($this->techUser)
            ->get(route('tech.contacts.show', $match))
            ->assertOk()
            ->assertViewIs('contact::Tech.show')
            ->assertSee('Search Match')
            ->assertSee('match@example.test');
    }

    #[Test]
    public function contact_show_displays_organization_or_client_and_site(): void
    {
        $client = Client::factory()->create(['name' => 'Show Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Show Site']);
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Show Contact',
        ]);
        $contact->relations()->create([
            'related_type' => $client->getMorphClass(),
            'related_id' => $client->id,
            'relation_type' => 'contact',
            'is_primary' => true,
        ]);
        $contact->relations()->create([
            'related_type' => $site->getMorphClass(),
            'related_id' => $site->id,
            'relation_type' => 'contact',
            'is_primary' => true,
        ]);
        Signal::query()->create([
            'source_domain' => 'email',
            'contact_id' => $contact->id,
            'client_id' => $client->id,
            'signal_type' => 'hard_bounce',
            'severity' => 'error',
            'confidence' => 95,
            'summary' => 'Inbound email classified as hard bounce.',
            'occurred_at' => now(),
        ]);

        $this->actingAs($this->techUser)
            ->get(route('tech.contacts.show', $contact))
            ->assertOk()
            ->assertSee('Organization / Client')
            ->assertSee('Show Client')
            ->assertSee('Site')
            ->assertSee('Show Site')
            ->assertSee('Signals')
            ->assertSee('Inbound email classified as hard bounce.');
    }

    #[Test]
    public function contact_index_respects_active_client_and_site_context(): void
    {
        $client = Client::factory()->create(['name' => 'Context Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Context Site']);
        $otherSite = ClientSite::factory()->create(['name' => 'Other Site']);

        $match = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Scoped Contact',
        ]);
        $match->relations()->create([
            'related_type' => $client->getMorphClass(),
            'related_id' => $client->id,
            'relation_type' => 'contact',
            'is_primary' => true,
        ]);
        $match->relations()->create([
            'related_type' => $site->getMorphClass(),
            'related_id' => $site->id,
            'relation_type' => 'site_contact',
            'is_primary' => true,
        ]);

        $other = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Other Client Contact',
        ]);
        $other->relations()->create([
            'related_type' => $otherSite->getMorphClass(),
            'related_id' => $otherSite->id,
            'relation_type' => 'site_contact',
            'is_primary' => true,
        ]);

        $this->actingAs($this->techUser)
            ->withSession(['active_client_id' => $client->id, 'active_site_id' => $site->id])
            ->get(route('tech.contacts.index'))
            ->assertOk()
            ->assertSee('Context Client')
            ->assertSee('Context Site')
            ->assertSee('Clear client context')
            ->assertSee('Scoped Contact')
            ->assertDontSee('Other Client Contact');
    }

    #[Test]
    public function contact_context_clear_removes_active_client_and_site_session(): void
    {
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);

        $this->actingAs($this->techUser)
            ->withSession(['active_client_id' => $client->id, 'active_site_id' => $site->id])
            ->post(route('tech.contacts.context.clear'))
            ->assertRedirect(route('tech.contacts.index'))
            ->assertSessionMissing('active_client_id')
            ->assertSessionMissing('active_site_id');
    }

    #[Test]
    public function contact_index_filters_can_override_active_context(): void
    {
        $sessionClient = Client::factory()->create(['name' => 'Session Client']);
        $sessionSite = ClientSite::factory()->create(['client_id' => $sessionClient->id, 'name' => 'Session Site']);
        $overrideClient = Client::factory()->create(['name' => 'Override Client']);
        $overrideSite = ClientSite::factory()->create(['client_id' => $overrideClient->id, 'name' => 'Override Site']);

        $sessionContact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Session Contact',
        ]);
        $sessionContact->relations()->create([
            'related_type' => $sessionSite->getMorphClass(),
            'related_id' => $sessionSite->id,
            'relation_type' => 'site_contact',
            'is_primary' => true,
        ]);

        $overrideContact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Override Contact',
        ]);
        $overrideContact->relations()->create([
            'related_type' => $overrideSite->getMorphClass(),
            'related_id' => $overrideSite->id,
            'relation_type' => 'site_contact',
            'is_primary' => true,
        ]);

        $this->actingAs($this->techUser)
            ->withSession(['active_client_id' => $sessionClient->id, 'active_site_id' => $sessionSite->id])
            ->get(route('tech.contacts.index', [
                'client_id' => $overrideClient->id,
                'site_id' => $overrideSite->id,
            ]))
            ->assertOk()
            ->assertSee('Session Client')
            ->assertSee('Session Site')
            ->assertSee('Override Contact')
            ->assertDontSee('Session Contact')
            ->assertSee('value="'.$overrideClient->id.'" selected', false)
            ->assertSee('value="'.$overrideSite->id.'" selected', false)
            ->assertSee('href="'.route('tech.contacts.index').'"', false);
    }

    #[Test]
    public function create_contact_form_locks_active_client_and_allows_site_choice(): void
    {
        $client = Client::factory()->create(['name' => 'Locked Client']);
        ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Locked Site']);
        ClientSite::factory()->create(['name' => 'Unrelated Site']);

        $this->actingAs($this->techUser)
            ->withSession(['active_client_id' => $client->id])
            ->get(route('tech.contacts.create'))
            ->assertOk()
            ->assertViewIs('contact::Tech.create')
            ->assertSee('Locked Client')
            ->assertSee('Locked Site')
            ->assertDontSee('Unrelated Site');
    }

    #[Test]
    public function live_contact_form_suggests_clients_from_organization(): void
    {
        $client = Client::factory()->create(['name' => 'Suggested Client']);
        ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Suggested Site']);

        $this->actingAs($this->techUser);

        Livewire::test(ContactForm::class)
            ->set('organization_name', 'Suggested')
            ->assertSee('Suggested Client')
            ->call('selectClient', $client->id)
            ->assertSet('client_id', $client->id)
            ->assertSet('organization_name', 'Suggested Client')
            ->assertSet('site_id', ClientSite::query()->where('client_id', $client->id)->where('is_default', true)->value('id'))
            ->assertSee('Suggested Site');
    }

    #[Test]
    public function live_contact_form_saves_client_relation_from_selected_organization_client(): void
    {
        $client = Client::factory()->create(['name' => 'Relation Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Main Site']);

        $this->actingAs($this->techUser);

        Livewire::test(ContactForm::class)
            ->set('display_name', 'Client Related Contact')
            ->set('email', 'client.related@example.test')
            ->set('phone', '+4733333333')
            ->set('job_title', 'Technical Contact')
            ->call('selectClient', $client->id)
            ->call('save');

        $contact = Contact::query()->where('display_name', 'Client Related Contact')->firstOrFail();

        $this->assertNull($contact->organization_name);
        $this->assertDatabaseHas('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $client->getMorphClass(),
            'related_id' => $client->id,
            'relation_type' => 'contact',
        ]);
        $this->assertDatabaseHas('client_users', [
            'contact_id' => $contact->id,
            'client_site_id' => $site->id,
            'email' => 'client.related@example.test',
        ]);
    }

    #[Test]
    public function contact_can_be_edited_with_shared_live_form(): void
    {
        $client = Client::factory()->create(['name' => 'Edit Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Default Site', 'is_default' => true]);
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Before Edit',
        ]);
        $contact->emails()->create([
            'label' => 'work',
            'email' => 'before@example.test',
            'is_primary' => true,
        ]);

        $this->actingAs($this->techUser)
            ->get(route('tech.contacts.edit', $contact))
            ->assertOk()
            ->assertViewIs('contact::Tech.edit')
            ->assertSee('Before Edit');

        Livewire::actingAs($this->techUser)
            ->test(ContactForm::class, ['contactId' => $contact->id])
            ->assertSet('organization_name', null)
            ->set('display_name', 'After Edit')
            ->set('email', 'after@example.test')
            ->set('phone', '+4744444444')
            ->call('selectClient', $client->id)
            ->assertSet('organization_name', 'Edit Client')
            ->call('save')
            ->assertRedirect(route('tech.contacts.show', $contact));

        $contact->refresh();

        $this->assertSame('After Edit', $contact->display_name);
        $this->assertNull($contact->organization_name);
        $this->assertDatabaseHas('contact_emails', [
            'contact_id' => $contact->id,
            'email' => 'after@example.test',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('client_users', [
            'contact_id' => $contact->id,
            'client_site_id' => $site->id,
            'email' => 'after@example.test',
        ]);
    }

    #[Test]
    public function edit_contact_form_can_search_new_client_after_existing_client_is_changed(): void
    {
        $currentClient = Client::factory()->create(['name' => 'Current Client']);
        $currentSite = ClientSite::factory()->create(['client_id' => $currentClient->id, 'name' => 'Current Site']);
        $newClient = Client::factory()->create(['name' => 'New Search Client']);
        ClientSite::factory()->create(['client_id' => $newClient->id, 'name' => 'New Site']);

        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Searchable Contact',
        ]);
        $contact->relations()->create([
            'related_type' => $currentClient->getMorphClass(),
            'related_id' => $currentClient->id,
            'relation_type' => 'contact',
            'is_primary' => true,
        ]);
        $contact->relations()->create([
            'related_type' => $currentSite->getMorphClass(),
            'related_id' => $currentSite->id,
            'relation_type' => 'contact',
            'is_primary' => true,
        ]);

        Livewire::actingAs($this->techUser)
            ->test(ContactForm::class, ['contactId' => $contact->id])
            ->assertSet('organization_name', 'Current Client')
            ->set('organization_name', 'New Search')
            ->assertSet('client_id', null)
            ->assertSet('site_id', null)
            ->assertSee('New Search Client');
    }

    #[Test]
    public function organization_free_text_clears_client_and_hides_site_field(): void
    {
        $client = Client::factory()->create(['name' => 'Linked Client']);
        ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Linked Site', 'is_default' => true]);

        Livewire::actingAs($this->techUser)
            ->test(ContactForm::class)
            ->call('selectClient', $client->id)
            ->assertSet('client_id', $client->id)
            ->assertSet('site_id', ClientSite::query()->where('client_id', $client->id)->where('is_default', true)->value('id'))
            ->assertSee('Site')
            ->set('organization_name', 'Fiktiv Organisasjon')
            ->assertSet('client_id', null)
            ->assertSet('site_id', null)
            ->assertDontSee('for="site_id"', false);
    }

    #[Test]
    public function edit_contact_form_saves_free_text_organization_and_removes_old_client_context(): void
    {
        $client = Client::factory()->create(['name' => 'Old Linked Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Old Linked Site', 'is_default' => true]);

        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Free Text Contact',
        ]);
        $contact->emails()->create([
            'label' => 'work',
            'email' => 'free-text@example.test',
            'is_primary' => true,
        ]);
        $contact->relations()->create([
            'related_type' => $client->getMorphClass(),
            'related_id' => $client->id,
            'relation_type' => 'contact',
            'is_primary' => true,
        ]);
        $contact->relations()->create([
            'related_type' => $site->getMorphClass(),
            'related_id' => $site->id,
            'relation_type' => 'contact',
            'is_primary' => true,
        ]);
        $contact->clientUser()->create([
            'client_site_id' => $site->id,
            'name' => 'Free Text Contact',
            'email' => 'free-text@example.test',
            'active' => true,
        ]);

        Livewire::actingAs($this->techUser)
            ->test(ContactForm::class, ['contactId' => $contact->id])
            ->set('organization_name', 'Fiktiv Organisasjon')
            ->call('save')
            ->assertRedirect(route('tech.contacts.show', $contact));

        $this->assertSame('Fiktiv Organisasjon', $contact->fresh()->organization_name);
        $this->assertDatabaseMissing('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $client->getMorphClass(),
            'related_id' => $client->id,
        ]);
        $this->assertDatabaseMissing('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $site->getMorphClass(),
            'related_id' => $site->id,
        ]);
        $this->assertDatabaseMissing('client_users', [
            'contact_id' => $contact->id,
            'client_site_id' => $site->id,
        ]);
    }

    #[Test]
    public function edit_contact_form_replaces_existing_client_context_when_new_client_is_saved(): void
    {
        $oldClient = Client::factory()->create(['name' => 'Old Client']);
        $oldSite = ClientSite::factory()->create(['client_id' => $oldClient->id, 'name' => 'Old Default', 'is_default' => true]);
        $newClient = Client::factory()->create(['name' => 'New Client']);
        $newSite = ClientSite::factory()->create(['client_id' => $newClient->id, 'name' => 'New Default', 'is_default' => true]);

        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Move Contact',
        ]);
        $contact->emails()->create([
            'label' => 'work',
            'email' => 'move@example.test',
            'is_primary' => true,
        ]);
        $contact->relations()->create([
            'related_type' => $oldClient->getMorphClass(),
            'related_id' => $oldClient->id,
            'relation_type' => 'contact',
            'is_primary' => true,
        ]);
        $contact->relations()->create([
            'related_type' => $oldSite->getMorphClass(),
            'related_id' => $oldSite->id,
            'relation_type' => 'contact',
            'is_primary' => true,
        ]);
        $contact->clientUser()->create([
            'client_site_id' => $oldSite->id,
            'name' => 'Move Contact',
            'email' => 'move@example.test',
            'active' => true,
        ]);

        Livewire::actingAs($this->techUser)
            ->test(ContactForm::class, ['contactId' => $contact->id])
            ->set('organization_name', 'New')
            ->call('selectClient', $newClient->id)
            ->assertSet('site_id', $newSite->id)
            ->call('save')
            ->assertRedirect(route('tech.contacts.show', $contact));

        $this->assertDatabaseMissing('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $oldClient->getMorphClass(),
            'related_id' => $oldClient->id,
        ]);
        $this->assertDatabaseMissing('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $oldSite->getMorphClass(),
            'related_id' => $oldSite->id,
        ]);
        $this->assertDatabaseHas('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $newClient->getMorphClass(),
            'related_id' => $newClient->id,
        ]);
        $this->assertDatabaseHas('client_users', [
            'contact_id' => $contact->id,
            'client_site_id' => $newSite->id,
            'email' => 'move@example.test',
        ]);
        $this->assertDatabaseMissing('client_users', [
            'contact_id' => $contact->id,
            'client_site_id' => $oldSite->id,
        ]);
    }

    #[Test]
    public function live_contact_form_updates_existing_contact_when_email_matches(): void
    {
        $client = Client::factory()->create(['name' => 'Existing Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Existing Site']);
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Existing Person',
        ]);
        $contact->emails()->create([
            'label' => 'work',
            'email' => 'existing@example.test',
            'is_primary' => true,
        ]);
        $contact->relations()->create([
            'related_type' => $client->getMorphClass(),
            'related_id' => $client->id,
            'relation_type' => 'contact',
            'is_primary' => true,
        ]);
        $contact->relations()->create([
            'related_type' => $site->getMorphClass(),
            'related_id' => $site->id,
            'relation_type' => 'contact',
            'is_primary' => true,
        ]);

        $this->actingAs($this->techUser);

        Livewire::test(ContactForm::class)
            ->set('display_name', 'Different Name')
            ->set('email', 'existing@example.test')
            ->set('phone', '+4799999999')
            ->set('job_title', 'Service Manager')
            ->set('relation_type', 'technical_contact')
            ->assertSee('Possible existing contact found')
            ->call('selectExistingContact', $contact->id)
            ->assertSet('display_name', 'Existing Person')
            ->assertSet('organization_name', 'Existing Client')
            ->assertSet('client_id', $client->id)
            ->assertSet('site_id', $site->id)
            ->assertSee('Updating existing contact')
            ->call('save')
            ->assertRedirect(route('tech.contacts.show', $contact));

        $this->assertSame(1, Contact::query()->count());
        $this->assertDatabaseHas('contact_phones', [
            'contact_id' => $contact->id,
            'phone' => '+4799999999',
            'is_primary' => true,
        ]);
        $this->assertSame('Service Manager', $contact->fresh()->job_title);
    }

    #[Test]
    public function duplicate_warning_disappears_when_duplicate_email_is_removed(): void
    {
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Duplicate Email Person',
        ]);
        $contact->emails()->create([
            'label' => 'work',
            'email' => 'duplicate@example.test',
            'is_primary' => true,
        ]);

        Livewire::actingAs($this->techUser)
            ->test(ContactForm::class)
            ->set('email', 'duplicate@example.test')
            ->assertSee('Possible existing contact found')
            ->set('email', '')
            ->assertDontSee('Possible existing contact found');
    }

    #[Test]
    public function creating_contact_uses_active_context_and_keeps_client_user_bridge(): void
    {
        $client = Client::factory()->create(['name' => 'Bridge Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Bridge Site']);

        $response = $this->actingAs($this->techUser)
            ->withSession(['active_client_id' => $client->id, 'active_site_id' => $site->id])
            ->post(route('tech.contacts.store'), [
                'display_name' => 'New Context Contact',
            'email' => 'new.context@example.test',
            'phone' => '+4711111111',
            'job_title' => 'Technical Contact',
            'relation_type' => 'technical_contact',
            ]);

        $contact = Contact::query()->where('display_name', 'New Context Contact')->firstOrFail();

        $response->assertRedirect(route('tech.contacts.show', $contact));
        $this->assertDatabaseHas('contact_emails', [
            'contact_id' => $contact->id,
            'email' => 'new.context@example.test',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $client->getMorphClass(),
            'related_id' => $client->id,
            'relation_type' => 'technical_contact',
        ]);
        $this->assertDatabaseHas('contact_relations', [
            'contact_id' => $contact->id,
            'related_type' => $site->getMorphClass(),
            'related_id' => $site->id,
            'relation_type' => 'technical_contact',
        ]);
        $this->assertDatabaseHas('client_users', [
            'contact_id' => $contact->id,
            'client_site_id' => $site->id,
            'email' => 'new.context@example.test',
        ]);
    }

    #[Test]
    public function direct_contact_store_does_not_create_duplicate_for_existing_email(): void
    {
        $existing = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Existing Email Contact',
        ]);
        $existing->emails()->create([
            'label' => 'work',
            'email' => 'same@example.test',
            'is_primary' => true,
        ]);

        $response = $this->actingAs($this->techUser)
            ->post(route('tech.contacts.store'), [
                'display_name' => 'New Duplicate Attempt',
                'email' => 'same@example.test',
                'phone' => '+4722222222',
                'job_title' => 'Billing Contact',
                'relation_type' => 'billing_contact',
            ]);

        $response->assertRedirect(route('tech.contacts.show', $existing));
        $this->assertSame(1, Contact::query()->count());
        $this->assertDatabaseHas('contact_phones', [
            'contact_id' => $existing->id,
            'phone' => '+4722222222',
        ]);
    }

    #[Test]
    public function direct_contact_store_does_not_create_duplicate_for_existing_phone(): void
    {
        $existing = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Existing Phone Contact',
        ]);
        $existing->phones()->create([
            'label' => 'mobile',
            'phone' => '+47 22 22 22 22',
            'is_primary' => true,
        ]);

        $response = $this->actingAs($this->techUser)
            ->post(route('tech.contacts.store'), [
                'display_name' => 'New Phone Duplicate Attempt',
                'email' => 'phone-match@example.test',
                'phone' => '22222222',
                'job_title' => 'Technical Contact',
                'relation_type' => 'technical_contact',
            ]);

        $response->assertRedirect(route('tech.contacts.show', $existing));
        $this->assertSame(1, Contact::query()->count());
        $this->assertDatabaseHas('contact_emails', [
            'contact_id' => $existing->id,
            'email' => 'phone-match@example.test',
        ]);
    }

    private function ownershipMoveFixture(): array
    {
        $oldClient = Client::factory()->create(['name' => 'Old Ownership Client', 'client_number' => '10001']);
        $oldSite = ClientSite::factory()->create(['client_id' => $oldClient->id, 'name' => 'Old Ownership Site', 'is_default' => true]);
        $newClient = Client::factory()->create(['name' => 'New Ownership Client', 'client_number' => '10002']);
        $newSite = ClientSite::factory()->create(['client_id' => $newClient->id, 'name' => 'New Ownership Site', 'is_default' => true]);
        $contact = $this->contactWithOwnership('Move Contact', 'move@example.test', $oldClient, $oldSite, 'technical_contact');

        return [$oldClient, $oldSite, $newClient, $newSite, $contact];
    }

    private function contactWithOwnership(
        string $displayName,
        string $email,
        Client $client,
        ClientSite $site,
        string $relationType = 'contact'
    ): Contact {
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => $displayName,
            'job_title' => 'Technical Contact',
        ]);
        $contact->emails()->create([
            'label' => 'work',
            'email' => $email,
            'is_primary' => true,
        ]);
        $contact->relations()->create([
            'related_type' => $client->getMorphClass(),
            'related_id' => $client->id,
            'relation_type' => $relationType,
            'is_primary' => true,
        ]);
        $contact->relations()->create([
            'related_type' => $site->getMorphClass(),
            'related_id' => $site->id,
            'relation_type' => $relationType,
            'is_primary' => true,
        ]);
        ClientUser::factory()->create([
            'contact_id' => $contact->id,
            'client_site_id' => $site->id,
            'name' => $displayName,
            'email' => $email,
            'role' => 'Technical Contact',
        ]);

        return $contact;
    }
}
