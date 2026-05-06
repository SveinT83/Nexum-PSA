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

        $response = $this->actingAs($this->tech)
            ->get(route('tech.assets.index'));

        $response->assertOk();
        $response->assertViewIs('asset::Tech.index');
        $response->assertViewHas('assets');
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
