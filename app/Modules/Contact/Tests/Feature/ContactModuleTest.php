<?php

namespace App\Modules\Contact\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Contact\Controllers\Tech\ContactController;
use App\Modules\Contact\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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
        $this->assertSame(ContactController::class.'@create', Route::getRoutes()->getByName('tech.contacts.create')->getActionName());
        $this->assertSame(ContactController::class.'@store', Route::getRoutes()->getByName('tech.contacts.store')->getActionName());
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
            ->assertSee('Scoped Contact')
            ->assertDontSee('Other Client Contact');
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
}
