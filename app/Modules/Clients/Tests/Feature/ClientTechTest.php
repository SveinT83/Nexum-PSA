<?php

namespace App\Modules\Clients\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientTechTest extends TestCase
{
    use RefreshDatabase;

    protected User $techUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a tech user and assign appropriate role/permissions if necessary
        // For now, we'll assume a user with 'tech' status or similar is needed.
        // Based on previous issues, 'auth' and 'tech' middleware are used.
        $this->techUser = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);

        // If the 'tech' middleware checks for a role, we might need to assign it.
        // For this environment, let's assume being logged in is a good start,
        // and we might need to adjust if the 'tech' middleware is strict.
        $this->techUser->assignRole('tech');
    }

    /** @test */
    public function it_can_list_clients()
    {
        Client::factory()->count(3)->create();

        $response = $this->actingAs($this->techUser)
            ->get(route('tech.clients.index'));

        $response->assertStatus(200);
        $response->assertViewIs('Tech.index');
        $response->assertViewHas('clients');
    }

    /** @test */
    public function it_can_show_a_client()
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->techUser)
            ->get(route('tech.clients.show', $client));

        $response->assertStatus(200);
        $response->assertViewIs('Tech.show');
        $response->assertViewHas('client');
        $this->assertEquals($client->id, session('active_client_id'));
    }

    /** @test */
    public function it_can_show_the_create_client_form()
    {
        $response = $this->actingAs($this->techUser)
            ->get(route('tech.clients.create'));

        $response->assertStatus(200);
        $response->assertViewIs('Tech.create');
        $response->assertViewHas('suggestedClientNumber');
    }

    /** @test */
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
