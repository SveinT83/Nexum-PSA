<?php

namespace App\Modules\Asset\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);

        // Assign necessary permissions
        $this->user->givePermissionTo([
            'asset.view',
        ]);
    }

    /** @test */
    public function it_can_search_by_name_and_hostname()
    {
        Asset::create(['name' => 'Web Server', 'hostname' => 'web-01', 'type' => 'server', 'ip_type' => 'fixed', 'status' => 'online']);
        Asset::create(['name' => 'DB Server', 'hostname' => 'db-01', 'type' => 'server', 'ip_type' => 'fixed', 'status' => 'online']);
        Asset::create(['name' => 'Office PC', 'hostname' => 'pc-office', 'type' => 'pc', 'ip_type' => 'fixed', 'status' => 'online']);

        $this->actingAs($this->user);

        // Search by name
        $response = $this->get(route('tech.assets.index', ['search' => 'Web']));
        $response->assertStatus(200);
        $response->assertSee('Web Server');
        $response->assertDontSee('DB Server');

        // Search by hostname
        $response = $this->get(route('tech.assets.index', ['search' => 'db-01']));
        $response->assertStatus(200);
        $response->assertSee('DB Server');
        $response->assertDontSee('Web Server');
    }

    /** @test */
    public function it_persists_search_and_filters_in_session()
    {
        $this->actingAs($this->user);

        // Apply filters
        $this->get(route('tech.assets.index', ['search' => 'test-search', 'type' => 'server']));

        // Revisit without parameters - should restore from session
        $response = $this->get(route('tech.assets.index'));
        $response->assertStatus(200);

        // Check if filters are applied in the view (this might need adjusting based on how we render them)
        // For now, check if the session has them
        $this->assertEquals('test-search', session('asset_filters_global.filters.search'));
        $this->assertEquals('server', session('asset_filters_global.filters.type'));
    }

    /** @test */
    public function it_isolates_global_and_client_scoped_state()
    {
        $client = Client::factory()->create();
        $this->actingAs($this->user);

        // Set global state
        $this->get(route('tech.assets.index', ['search' => 'global-search']));

        // Set client state
        $this->get(route('tech.clients.assets.index', ['client' => $client->id, 'search' => 'client-search']));

        // Verify isolation
        $this->assertEquals('global-search', session('asset_filters_global.filters.search'));
        $this->assertEquals('client-search', session('asset_filters_client_' . $client->id . '.filters.search'));
    }

    /** @test */
    public function explicit_query_overrides_remembered_state()
    {
        $this->actingAs($this->user);

        // Set initial state
        $this->get(route('tech.assets.index', ['search' => 'initial']));

        // Override with explicit query
        $response = $this->get(route('tech.assets.index', ['search' => 'new-search']));

        $this->assertEquals('new-search', session('asset_filters_global.filters.search'));
    }

    /** @test */
    public function clear_filters_removes_remembered_state()
    {
        $this->actingAs($this->user);

        // Set state
        $this->get(route('tech.assets.index', ['search' => 'to-be-cleared']));

        // Clear filters
        $this->get(route('tech.assets.index', ['clear_filters' => 1]));

        $this->assertNull(session('asset_filters_global'));
    }
}
