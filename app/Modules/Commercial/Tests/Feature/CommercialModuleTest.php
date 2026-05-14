<?php

namespace App\Modules\Commercial\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Commercial\Controllers\Admin\EconomyController;
use App\Modules\Commercial\Controllers\Tech\Contracts\ContractController;
use App\Modules\Commercial\Controllers\Tech\Contracts\PublicContractController;
use App\Modules\Commercial\Controllers\Tech\Sla\SlaController;
use App\Modules\Commercial\Controllers\Tech\Services\ServiceController;
use App\Modules\Commercial\Models\Sla\Sla;
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

    #[Test]
    public function tech_user_can_open_sla_show_view(): void
    {
        $this->assertSame(
            SlaController::class . '@show',
            Route::getRoutes()->getByName('tech.sla.show')->getActionName()
        );

        $sla = Sla::create([
            'name' => 'Standard support',
            'description' => 'Default response policy for support agreements.',
            'low_firstResponse' => 24,
            'low_firstResponse_type' => 'Hours',
            'low_onsite' => 48,
            'low_onsite_type' => 'Hours',
            'medium_firstResponse' => 12,
            'medium_firstResponse_type' => 'Hours',
            'medium_onsite' => 24,
            'medium_onsite_type' => 'Hours',
            'high_firstResponse' => 6,
            'high_firstResponse_type' => 'Hours',
            'high_onsite' => 12,
            'high_onsite_type' => 'Hours',
            'created_by_user_id' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.sla.show', $sla))
            ->assertOk()
            ->assertViewIs('commercial::Tech.cs.sla.form')
            ->assertSee('Standard support');
    }

    #[Test]
    public function tech_user_can_open_sla_create_and_edit_forms(): void
    {
        $sla = Sla::create([
            'name' => 'Edit support',
            'description' => 'Editable response policy.',
            'low_firstResponse' => 24,
            'low_firstResponse_type' => 'Hours',
            'low_onsite' => 48,
            'low_onsite_type' => 'Hours',
            'medium_firstResponse' => 12,
            'medium_firstResponse_type' => 'Hours',
            'medium_onsite' => 24,
            'medium_onsite_type' => 'Hours',
            'high_firstResponse' => 6,
            'high_firstResponse_type' => 'Hours',
            'high_onsite' => 12,
            'high_onsite_type' => 'Hours',
            'created_by_user_id' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.sla.create'))
            ->assertOk()
            ->assertViewIs('commercial::Tech.cs.sla.form')
            ->assertSee('Create SLA Policy');

        $this->actingAs($this->tech)
            ->get(route('tech.sla.edit', $sla))
            ->assertOk()
            ->assertViewIs('commercial::Tech.cs.sla.form')
            ->assertSee('Edit SLA Policy');
    }
}
