<?php

namespace App\Modules\Sales\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Sales\Controllers\Admin\SalesSettingsController;
use App\Modules\Sales\Controllers\Tech\LeadsController;
use App\Modules\Sales\Controllers\Tech\SalesController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);
        Role::create(['name' => 'Admin']);

        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
    }

    #[Test]
    public function tech_user_can_open_sales_index_from_sales_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.sales.index');

        $this->assertSame(SalesController::class . '@index', $route->getActionName());

        $this->actingAs($this->tech)
            ->get(route('tech.sales.index'))
            ->assertOk()
            ->assertViewIs('sales::Tech.Sales.index');
    }

    #[Test]
    public function tech_user_can_open_sales_leads_from_sales_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.sales.leads.index');

        $this->assertSame(LeadsController::class . '@index', $route->getActionName());

        $this->actingAs($this->tech)
            ->get(route('tech.sales.leads.index'))
            ->assertOk()
            ->assertViewIs('sales::Tech.Sales.leads.index');
    }

    #[Test]
    public function admin_can_open_sales_settings_from_sales_module(): void
    {
        $rulesRoute = Route::getRoutes()->getByName('tech.admin.settings.sales.rules');
        $workflowsRoute = Route::getRoutes()->getByName('tech.admin.settings.sales.workflows');

        $this->assertSame(SalesSettingsController::class . '@rules', $rulesRoute->getActionName());
        $this->assertSame(SalesSettingsController::class . '@workflows', $workflowsRoute->getActionName());

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.sales.rules'))
            ->assertOk()
            ->assertViewIs('sales::Admin.Settings.rules.index');

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.sales.workflows'))
            ->assertOk()
            ->assertViewIs('sales::Admin.Settings.workflows.index');
    }
}
