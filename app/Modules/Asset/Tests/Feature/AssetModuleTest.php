<?php

namespace App\Modules\Asset\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature coverage for the module-level Asset routes.
 *
 * These tests verify the public route contracts that must keep working while
 * the implementation lives in `app/Modules/Asset`.
 */
class AssetModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);

        $this->tech = User::create([
            'name' => 'Asset Tech',
            'email' => 'asset-tech@example.test',
            'password' => Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->tech->assignRole('Tech');
    }

    #[Test]
    public function tech_user_can_list_assets(): void
    {
        $client = Client::factory()->create();
        Asset::create([
            'client_id' => $client->id,
            'name' => 'Main Firewall',
            'type' => 'firewall',
            'ip_type' => 'fixed',
            'status' => 'online',
        ]);
        Asset::create([
            'client_id' => $client->id,
            'name' => 'Accounting Laptop',
            'type' => 'laptop',
            'ip_type' => 'dhcp',
            'status' => 'offline',
        ]);

        $response = $this->actingAs($this->tech)
            ->get(route('tech.assets.index', ['sort' => 'type', 'direction' => 'asc']));

        $response->assertOk();
        $response->assertViewIs('asset::Tech.index');
        $response->assertViewHas('assets');
        $response->assertSee('sort=name', false);
        $response->assertSee('sort=type', false);
        $response->assertSee('sort=client_site', false);
        $response->assertSee('sort=status', false);
        $response->assertSee('sort=last_seen_at', false);
        $response->assertSeeInOrder(['Main Firewall', 'Accounting Laptop']);
        $response->assertSee('New Asset');
        $response->assertSee('data-href="'.route('tech.assets.show', ['asset' => Asset::firstOrFail()->id, 'tab' => 'summary']).'"', false);
        $response->assertSee('—');
        $response->assertDontSee('View</a>', false);
    }

    #[Test]
    public function client_scoped_route_uses_asset_module_controller(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->tech)
            ->get(route('tech.clients.assets.index', $client));

        $response->assertOk();
        $response->assertViewIs('asset::Tech.index');
        $response->assertSee($client->name);
        $response->assertSee('Back');
        $response->assertSee('accordion-button collapsed', false);
        $response->assertSee('Integrations');
        $response->assertDontSee('Back to Client');
    }

    #[Test]
    public function tech_user_can_view_asset_with_compact_header(): void
    {
        $client = Client::factory()->create();
        $asset = Asset::create([
            'client_id' => $client->id,
            'name' => 'Compact Header Server',
            'type' => 'server',
            'ip_type' => 'fixed',
            'status' => 'online',
        ]);

        $response = $this->actingAs($this->tech)
            ->get(route('tech.assets.show', ['asset' => $asset, 'tab' => 'summary']));

        $response->assertOk();
        $response->assertViewIs('asset::Tech.show');
        $response->assertSee('Compact Header Server');
        $response->assertSee('<h1>Compact Header Server</h1>', false);
        $response->assertSee('Asset Details');
        $response->assertSee('Edit');
        $response->assertSee('Back');
        $response->assertSee('accordion-button collapsed', false);
        $response->assertSeeText('Status');
        $response->assertSeeText('Lifecycle');
        $response->assertSeeText('Documentation');
        $response->assertDontSee('Back to Assets');
    }

    #[Test]
    public function tech_user_can_store_asset_through_http_fallback(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->tech)
            ->post(route('tech.assets.store'), [
                'client_id' => $client->id,
                'name' => 'Reception Laptop',
                'type' => 'laptop',
                'ip_type' => 'dhcp',
                'hostname' => 'reception-laptop',
            ]);

        $asset = Asset::firstOrFail();

        $response->assertRedirect(route('tech.assets.show', $asset));
        $this->assertSame('Reception Laptop', $asset->name);
        $this->assertSame($client->id, $asset->client_id);
    }

    #[Test]
    public function authenticated_api_user_can_list_assets(): void
    {
        $client = Client::factory()->create();
        Asset::create([
            'client_id' => $client->id,
            'name' => 'API Server',
            'type' => 'server',
            'ip_type' => 'fixed',
            'status' => 'online',
        ]);

        Sanctum::actingAs($this->tech);

        $response = $this->getJson(route('api.v1.assets.index'));

        $response->assertOk();
        $response->assertJsonPath('data.0.name', 'API Server');
    }
}
