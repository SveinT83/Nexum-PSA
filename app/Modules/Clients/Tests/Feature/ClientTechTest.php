<?php

namespace App\Modules\Clients\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientTechTest extends TestCase
{
    use RefreshDatabase;

    protected User $techUser;

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
    public function it_can_list_clients()
    {
        Client::factory()->count(3)->create();

        $response = $this->actingAs($this->techUser)
            ->get(route('tech.clients.index'));

        $response->assertStatus(200);
        $response->assertViewIs('clients::Tech.index');
        $response->assertViewHas('clients');
    }

    #[Test]
    public function it_can_show_a_client()
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->techUser)
            ->get(route('tech.clients.show', $client));

        $response->assertStatus(200);
        $response->assertViewIs('clients::Tech.show');
        $response->assertViewHas('client');
        $this->assertEquals($client->id, session('active_client_id'));
    }

    #[Test]
    public function it_can_list_client_users_for_a_client()
    {
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $clientUser = ClientUser::factory()->create(['client_site_id' => $site->id]);

        $response = $this->actingAs($this->techUser)
            ->get(route('tech.clients.users.index', $client));

        $response->assertStatus(200);
        $response->assertViewIs('clients::Tech.Users.index');
        $response->assertViewHas('users');
        $response->assertSee($clientUser->name);
    }

    #[Test]
    public function it_can_show_the_create_client_form()
    {
        $response = $this->actingAs($this->techUser)
            ->get(route('tech.clients.create'));

        $response->assertStatus(200);
        $response->assertViewIs('clients::Tech.create');
        $response->assertViewHas('suggestedClientNumber');
    }

    #[Test]
    public function it_can_store_a_new_client()
    {
        $clientData = [
            'name' => 'New Test Client',
            'client_number' => '99999',
            'org_no' => '123456789',
            'billing_email' => 'billing@test.com',
            'site_name' => 'Main Site',
            'user_name' => 'Primary Contact',
            'user_email' => 'contact@test.com',
            'user_role' => 'Daglig leder',
        ];

        $response = $this->actingAs($this->techUser)
            ->post(route('tech.clients.store'), $clientData);

        $response->assertRedirect(route('tech.clients.index'));
        $this->assertDatabaseHas('clients', ['name' => 'New Test Client', 'client_number' => '99999']);
        $this->assertDatabaseHas('client_sites', ['name' => 'Main Site']);
        $this->assertDatabaseHas('client_users', ['name' => 'Primary Contact', 'email' => 'contact@test.com']);
    }
}
