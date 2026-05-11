<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Integration\Controllers\Admin\ApiController;
use App\Modules\Integration\Controllers\Admin\IntegrationsController;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
