<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Controllers\Admin\ApiController;
use App\Modules\Integration\Controllers\Admin\IntegrationsController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IntegrationModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin']);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
    }

    #[Test]
    public function admin_can_open_integration_index_from_integration_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.admin.system.integrations.index');

        $this->assertSame(IntegrationsController::class . '@index', $route->getActionName());

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.index'))
            ->assertOk()
            ->assertViewIs('integration::Tech.Admin.System.Integrations.index')
            ->assertViewHas('integrations');
    }

    #[Test]
    public function admin_can_open_api_management_from_integration_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.admin.system.integrations.api.index');

        $this->assertSame(ApiController::class . '@index', $route->getActionName());

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.api.index'))
            ->assertOk()
            ->assertViewIs('integration::Tech.Admin.System.Integrations.api.index')
            ->assertViewHas('apiKeys');
    }

    #[Test]
    public function admin_can_open_book_stack_settings_from_integration_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.admin.system.integrations.book_stack.settings');

        $this->assertSame(IntegrationsController::class . '@bookStackSettings', $route->getActionName());

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.book_stack.settings'))
            ->assertOk()
            ->assertViewIs('integration::Tech.Admin.System.Integrations.book_stack.settings')
            ->assertViewHas('integration');
    }

    #[Test]
    public function admin_can_save_book_stack_configuration_without_api_credentials(): void
    {
        Http::fake();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.book_stack.update'), [
                'server' => 'https://docs.example.test/',
                'sync_interval_minutes' => 10,
            ])
            ->assertRedirect();

        $integration = Integration::where('type', 'book_stack')->firstOrFail();

        $this->assertSame('BookStack', $integration->name);
        $this->assertSame('https://docs.example.test', $integration->server);
        $this->assertFalse($integration->is_healthy);
        $this->assertNull($integration->last_error);
        $this->assertSame(10, $integration->config['sync_interval_minutes']);
        $this->assertTrue($integration->config['read_only']);

        Http::assertNothingSent();
    }

    #[Test]
    public function admin_can_test_book_stack_configuration_when_credentials_exist(): void
    {
        Http::fake([
            'https://docs.example.test/api/books*' => Http::response(['data' => []], 200),
        ]);

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'is_healthy' => false,
            'config' => [
                'sync_interval_minutes' => 10,
                'read_only' => true,
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.book_stack.test'))
            ->assertRedirect();

        $integration->refresh();

        $this->assertTrue($integration->is_healthy);
        $this->assertNull($integration->last_error);

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://docs.example.test/api/books?count=1'
            && $request->hasHeader('Authorization', 'Token token-id:token-secret')
        );
    }
}
