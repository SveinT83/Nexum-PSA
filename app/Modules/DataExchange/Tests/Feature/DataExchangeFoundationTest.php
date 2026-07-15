<?php

namespace App\Modules\DataExchange\Tests\Feature;

use App\Models\Core\User;
use App\Modules\DataExchange\Controllers\Admin\DataExchangeController;
use App\Modules\DataExchange\Models\DataExchangeFile;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Models\DataExchangeRun;
use App\Modules\DataExchange\Support\DataExchangeFieldDefinition;
use App\Modules\DataExchange\Support\DataExchangeSourceDefinition;
use App\Modules\DataExchange\Support\DataExchangeSourceRegistry;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DataExchangeFoundationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function data_exchange_route_is_owned_by_data_exchange_module(): void
    {
        $this->assertSame(
            DataExchangeController::class.'@index',
            Route::getRoutes()->getByName('tech.admin.system.data-exchange.index')->getActionName(),
        );
    }

    #[Test]
    public function admin_with_view_permission_can_open_foundation_page(): void
    {
        $this->seed(PermissionSeeder::class);

        $role = Role::query()->create(['name' => 'Data Exchange Admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['data_exchange.view', 'system.view']);

        $user = User::query()->create([
            'name' => 'Data Exchange Admin',
            'email' => 'data-exchange-admin@example.test',
            'password' => Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('tech.admin.system.data-exchange.index'))
            ->assertOk()
            ->assertViewIs('dataexchange::Admin.index')
            ->assertSee('Data Exchange')
            ->assertSee('Profiles')
            ->assertSee('Registered Sources')
            ->assertDontSee('Create profile');
    }

    #[Test]
    public function data_exchange_foundation_models_persist_minimal_records(): void
    {
        $profile = DataExchangeProfile::query()->create([
            'name' => 'Economy order export',
            'key' => 'economy_orders_export',
            'direction' => DataExchangeProfile::DIRECTION_EXPORT,
            'format' => 'csv',
            'status' => DataExchangeProfile::STATUS_DRAFT,
        ]);

        $run = DataExchangeRun::query()->create([
            'profile_id' => $profile->id,
            'direction' => DataExchangeProfile::DIRECTION_EXPORT,
            'status' => DataExchangeRun::STATUS_QUEUED,
            'trigger_type' => 'manual',
        ]);

        DataExchangeFile::query()->create([
            'profile_id' => $profile->id,
            'run_id' => $run->id,
            'disk' => 'local',
            'path' => 'data-exchange/test.csv',
            'filename' => 'test.csv',
            'format' => 'csv',
            'size_bytes' => 10,
        ]);

        $this->assertDatabaseHas('data_exchange_profiles', [
            'key' => 'economy_orders_export',
        ]);
        $this->assertDatabaseHas('data_exchange_runs', [
            'profile_id' => $profile->id,
            'status' => DataExchangeRun::STATUS_QUEUED,
        ]);
        $this->assertDatabaseHas('data_exchange_files', [
            'filename' => 'test.csv',
        ]);
    }

    #[Test]
    public function source_registry_blocks_secret_like_fields(): void
    {
        $registry = new DataExchangeSourceRegistry();

        $registry->register(new DataExchangeSourceDefinition(
            key: 'users',
            label: 'Users',
            module: 'UserManagement',
            fields: [
                new DataExchangeFieldDefinition('name', 'Name'),
                new DataExchangeFieldDefinition('password', 'Password'),
                new DataExchangeFieldDefinition('remember_token', 'Remember token'),
                new DataExchangeFieldDefinition('two_factor_secret', 'Two-factor secret'),
                new DataExchangeFieldDefinition('api_key', 'API key'),
                new DataExchangeFieldDefinition('token_budget', 'Token budget'),
            ],
        ));

        $source = $registry->get('users');

        $this->assertNotNull($source);
        $this->assertFalse($source->field('password')->exportable);
        $this->assertTrue($source->field('password')->blocked);
        $this->assertFalse($source->field('remember_token')->exportable);
        $this->assertFalse($source->field('two_factor_secret')->exportable);
        $this->assertFalse($source->field('api_key')->exportable);
        $this->assertTrue($source->field('token_budget')->exportable);
        $this->assertSame(['name', 'token_budget'], array_map(
            fn (DataExchangeFieldDefinition $field): string => $field->key,
            $source->exportableFields(),
        ));
    }
}
