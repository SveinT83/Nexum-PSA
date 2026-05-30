<?php

namespace App\Modules\Contact\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Contact\Controllers\Tech\ContactController;
use App\Modules\Contact\Livewire\Tech\ContactForm;
use App\Modules\Contact\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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

        $this->actingAs($this->techUser)
            ->get(route('tech.contacts.show', $contact))
            ->assertOk()
            ->assertSee('Organization / Client')
            ->assertSee('Show Client')
            ->assertSee('Site')
            ->assertSee('Show Site');
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
}
