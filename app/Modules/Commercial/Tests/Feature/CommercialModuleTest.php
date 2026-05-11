<?php

namespace App\Modules\Commercial\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Commercial\Controllers\Admin\EconomyController;
use App\Modules\Commercial\Controllers\Tech\Contracts\ContractController;
use App\Modules\Commercial\Controllers\Tech\Contracts\PublicContractController;
use App\Modules\Commercial\Controllers\Tech\Services\ServiceController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CommercialModuleTest extends TestCase
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
    public function tech_commercial_routes_are_owned_by_commercial_module(): void
    {
        $this->assertSame(
            ContractController::class . '@index',
            Route::getRoutes()->getByName('tech.contracts.index')->getActionName()
        );

        $this->assertSame(
            ServiceController::class . '@index',
            Route::getRoutes()->getByName('tech.services.index')->getActionName()
        );

        $this->actingAs($this->tech)
            ->get(route('tech.contracts.index'))
            ->assertOk()
            ->assertViewIs('commercial::Tech.cs.contracts.index');

        $this->actingAs($this->tech)
            ->get(route('tech.services.index'))
            ->assertOk()
            ->assertViewIs('commercial::Tech.cs.services.index');
    }

    #[Test]
    public function admin_commercial_settings_route_is_owned_by_commercial_module(): void
    {
        $this->assertSame(
            EconomyController::class . '@index',
            Route::getRoutes()->getByName('tech.admin.settings.economy')->getActionName()
        );

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.economy'))
            ->assertOk()
            ->assertViewIs('commercial::Admin.economy.index');
    }

    #[Test]
    public function public_contract_route_is_owned_by_commercial_module(): void
    {
        $this->assertSame(
            PublicContractController::class . '@view',
            Route::getRoutes()->getByName('contracts.public.view')->getActionName()
        );
    }
}
