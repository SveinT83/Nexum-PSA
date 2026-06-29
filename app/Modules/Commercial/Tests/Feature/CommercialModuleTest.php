<?php

namespace App\Modules\Commercial\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Commercial\Models\Cost;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Controllers\Admin\UnitsController;
use App\Modules\Commercial\Controllers\Tech\Contracts\ContractController;
use App\Modules\Commercial\Controllers\Tech\Contracts\PublicContractController;
use App\Modules\Commercial\Controllers\Tech\Costs\CostController;
use App\Modules\Commercial\Controllers\Tech\Package\PackageController;
use App\Modules\Commercial\Controllers\Tech\Rates\TimeRateController;
use App\Modules\Commercial\Controllers\Tech\Sla\SlaController;
use App\Modules\Commercial\Controllers\Tech\Services\ServiceController;
use App\Modules\Commercial\Livewire\Tech\Contracts\ContractItemsEditor;
use App\Modules\Commercial\Livewire\Tech\ServiceLegal;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Commercial\Models\Packages\Package;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Commercial\Models\Terms\terms as CommercialTerm;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\System\Support\CompanyProfileSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
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
    public function authenticated_api_user_can_manage_commercial_catalogue_records(): void
    {
        $client = Client::factory()->create(['name' => 'API Commercial Client']);
        $unit = Units::query()->create(['name' => 'Month', 'short' => 'mo']);

        Sanctum::actingAs($this->tech, ['commercial.read', 'commercial.create', 'commercial.update']);

        $sla = $this->postJson(route('api.v1.commercial.slas.store'), [
            'name' => 'API Standard SLA',
            'description' => 'Standard API SLA.',
            'is_default' => true,
            'low_firstResponse' => 8,
            'low_firstResponse_type' => 'hours',
            'low_onsite' => 2,
            'low_onsite_type' => 'days',
            'medium_firstResponse' => 4,
            'medium_firstResponse_type' => 'hours',
            'medium_onsite' => 1,
            'medium_onsite_type' => 'days',
            'high_firstResponse' => 1,
            'high_firstResponse_type' => 'hours',
            'high_onsite' => 4,
            'high_onsite_type' => 'hours',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'API Standard SLA')
            ->assertJsonPath('data.is_default', true);

        $slaId = $sla->json('data.id');

        $service = $this->postJson(route('api.v1.commercial.services.store'), [
            'sku' => 'API-MONITORING',
            'name' => 'API Monitoring',
            'unitId' => $unit->id,
            'sla_id' => $slaId,
            'status' => 'Active',
            'availability_audience' => 'business',
            'orderable' => true,
            'taxable' => 25,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => 990,
            'price_including_tax' => 1237.50,
            'short_description' => 'Managed monitoring.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.sku', 'API-MONITORING')
            ->assertJsonPath('data.sla.id', $slaId)
            ->assertJsonPath('data.orderable', true);

        $serviceId = $service->json('data.id');

        $this->patchJson(route('api.v1.commercial.services.update', $serviceId), [
            'name' => 'API Monitoring Plus',
            'price_ex_vat' => 1290,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'API Monitoring Plus');

        $contract = $this->postJson(route('api.v1.commercial.contracts.store'), [
            'client_id' => $client->id,
            'sla_id' => $slaId,
            'description' => 'API managed contract draft.',
            'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'auto_renew' => true,
            'renewal_months' => 12,
        ])
            ->assertCreated()
            ->assertJsonPath('data.client.id', $client->id)
            ->assertJsonPath('data.approval_status', 'draft')
            ->assertJsonPath('data.auto_renew', true);

        $contractId = $contract->json('data.id');

        $this->patchJson(route('api.v1.commercial.contracts.update', $contractId), [
            'description' => 'Updated API contract draft.',
            'renewal_months' => 24,
        ])
            ->assertOk()
            ->assertJsonPath('data.description', 'Updated API contract draft.')
            ->assertJsonPath('data.renewal_months', 24);

        $rate = $this->postJson(route('api.v1.commercial.time-rates.store'), [
            'name' => 'API Hour',
            'code' => 'API-HOUR',
            'rate_type' => 'labor',
            'unit' => 'hour',
            'amount_ex_vat' => 1250,
            'currency' => 'NOK',
            'applies_without_contract' => true,
            'applies_with_contract' => true,
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'API-HOUR')
            ->assertJsonPath('data.slug', 'api-hour');

        $this->patchJson(route('api.v1.commercial.time-rates.update', $rate->json('data.id')), [
            'amount_ex_vat' => 1350,
            'sort_order' => 10,
        ])
            ->assertOk()
            ->assertJsonPath('data.sort_order', 10);

        $this->getJson(route('api.v1.commercial.services.index', ['q' => 'Monitoring Plus']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $serviceId);
    }

    #[Test]
    public function commercial_read_api_token_cannot_write_commercial_records(): void
    {
        $unit = Units::query()->create(['name' => 'Month', 'short' => 'mo']);
        Services::query()->create([
            'sku' => 'READONLY',
            'name' => 'Read Only Service',
            'unitId' => $unit->id,
            'status' => 'Active',
            'billing_cycle' => 'monthly',
            'price_ex_vat' => 100,
            'created_by_user_id' => $this->tech->id,
            'updated_by_user_id' => $this->tech->id,
        ]);

        Sanctum::actingAs($this->tech, ['commercial.read']);

        $this->getJson(route('api.v1.commercial.services.index'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Read Only Service');

        $this->postJson(route('api.v1.commercial.services.store'), [
            'sku' => 'BLOCKED',
            'name' => 'Blocked Service',
            'unitId' => $unit->id,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => 100,
        ])->assertForbidden();
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

        $this->assertSame(
            PackageController::class . '@index',
            Route::getRoutes()->getByName('tech.packages.index')->getActionName()
        );

        $this->assertSame(
            CostController::class . '@index',
            Route::getRoutes()->getByName('tech.costs.index')->getActionName()
        );

        $this->actingAs($this->tech)
            ->get(route('tech.contracts.index'))
            ->assertOk()
            ->assertViewIs('commercial::Tech.cs.contracts.index')
            ->assertSee('<h1>Contracts</h1>', false)
            ->assertSee('bi-arrow-left')
            ->assertSee('contract_search')
            ->assertSee('contractFiltersCollapse')
            ->assertSee('New Contract')
            ->assertSee('SLA')
            ->assertSee('sort=id', false)
            ->assertSee('sort=client', false)
            ->assertSee('sort=status', false)
            ->assertSee('sort=start_date', false)
            ->assertSee('sort=end_date', false)
            ->assertSee('sort=monthly_price', false)
            ->assertSee('sort=yearly_profit', false);

        $this->actingAs($this->tech)
            ->get(route('tech.services.index'))
            ->assertOk()
            ->assertViewIs('commercial::Tech.cs.services.index')
            ->assertSee('<h1>Services</h1>', false)
            ->assertSee('bi-arrow-left')
            ->assertSee('service_search')
            ->assertSee('serviceFiltersCollapse')
            ->assertSee('New Service')
            ->assertSee('sort=sku', false)
            ->assertSee('sort=name', false)
            ->assertSee('sort=price', false)
            ->assertSee('sort=billing_cycle', false)
            ->assertSee('sort=status', false)
            ->assertSee('sort=updated_at', false);

        $this->actingAs($this->tech)
            ->get(route('tech.packages.index'))
            ->assertOk()
            ->assertViewIs('commercial::Tech.cs.packages.index')
            ->assertSee('<h1>Packages</h1>', false)
            ->assertSee('bi-arrow-left')
            ->assertSee('package_search')
            ->assertSee('packageFiltersCollapse')
            ->assertSee('New Package')
            ->assertSee('sort=name', false)
            ->assertSee('sort=description', false)
            ->assertSee('sort=services', false)
            ->assertSee('sort=status', false)
            ->assertSee('sort=updated_at', false);

        $this->actingAs($this->tech)
            ->get(route('tech.costs.index'))
            ->assertOk()
            ->assertViewIs('commercial::Tech.cs.costs.index')
            ->assertSee('<h1>Costs</h1>', false)
            ->assertSee('bi-arrow-left')
            ->assertSee('cost_search')
            ->assertSee('costFiltersCollapse')
            ->assertSee('New Cost')
            ->assertSee('sort=name', false)
            ->assertSee('sort=cost', false)
            ->assertSee('sort=recurrence', false)
            ->assertSee('sort=vendor', false)
            ->assertSee('sort=updated_at', false);
    }

    #[Test]
    public function commercial_admin_settings_pages_do_not_render_legacy_view_specs(): void
    {
        $this->assertSame('/tech/admin/settings/cs/contracts', route('tech.admin.settings.cs.contracts', absolute: false));

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.cs.contracts'))
            ->assertOk()
            ->assertSee('Commercial Contract Settings')
            ->assertSee(route('tech.contracts.index'), false)
            ->assertSee(route('tech.sla.index'), false)
            ->assertDontSee('View Specification')
            ->assertDontSee('Status: Not completed');

        $this->actingAs($this->admin)
            ->get('/tech/admin/settings/cs/contacts')
            ->assertRedirect('/tech/admin/settings/cs/contracts');

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.cs.services'))
            ->assertOk()
            ->assertSee('Commercial Service Settings')
            ->assertSee(route('tech.services.index'), false)
            ->assertSee(route('tech.packages.index'), false)
            ->assertDontSee('View Specification')
            ->assertDontSee('Status: Not completed');
    }

    #[Test]
    public function admin_can_update_client_timebank_quick_policy(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.cs.timebank-policy'))
            ->assertOk()
            ->assertViewIs('commercial::Admin.settings.timebank-policy')
            ->assertSee('Client Timebank Policy')
            ->assertSee('Allow direct overuse')
            ->assertSee('quick_timebank_max_minutes', false);

        $this->actingAs($this->admin)
            ->put(route('tech.admin.settings.cs.timebank-policy.update'), [
                'quick_timebank_enabled' => '1',
                'quick_timebank_require_remaining' => '0',
                'quick_timebank_allow_overuse' => '1',
                'quick_timebank_require_note' => '0',
                'quick_timebank_max_minutes' => 45,
            ])
            ->assertRedirect(route('tech.admin.settings.cs.timebank-policy'));

        $setting = CommonSetting::query()
            ->where('type', 'commercial')
            ->where('name', 'client_timebank_quick_policy')
            ->firstOrFail();

        $payload = json_decode($setting->json, true);

        $this->assertTrue($payload['quick_timebank_enabled']);
        $this->assertFalse($payload['quick_timebank_require_remaining']);
        $this->assertTrue($payload['quick_timebank_allow_overuse']);
        $this->assertFalse($payload['quick_timebank_require_note']);
        $this->assertSame(45, $payload['quick_timebank_max_minutes']);
    }

    #[Test]
    public function contract_index_can_search_filter_and_sort_contracts(): void
    {
        $acme = Client::factory()->create(['name' => 'Acme Managed Services']);
        $zenith = Client::factory()->create(['name' => 'Zenith Operations']);
        $sla = $this->createSla('Managed Contract SLA');

        Contracts::query()->create([
            'client_id' => $zenith->id,
            'created_by' => $this->tech->id,
            'description' => 'Zenith monthly agreement',
            'approval_status' => 'draft',
            'start_date' => now()->addDays(20)->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
        ]);

        Contracts::query()->create([
            'client_id' => $acme->id,
            'created_by' => $this->tech->id,
            'description' => 'Acme support agreement',
            'approval_status' => 'sent_contract',
            'sla_id' => $sla->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.contracts.index', ['sort' => 'client', 'direction' => 'asc']))
            ->assertOk()
            ->assertSeeInOrder(['Acme Managed Services', 'Zenith Operations']);

        $searchResponse = $this->actingAs($this->tech)
            ->get(route('tech.contracts.index', ['q' => 'Acme']))
            ->assertOk()
            ->assertSee('Acme Managed Services');

        $this->assertSame(
            ['Acme Managed Services'],
            $searchResponse->viewData('contracts')->getCollection()->pluck('client.name')->all()
        );

        $statusResponse = $this->actingAs($this->tech)
            ->get(route('tech.contracts.index', ['status' => 'sent_contract']))
            ->assertOk()
            ->assertSee('Acme Managed Services')
            ->assertSee('Managed Contract SLA');

        $this->assertSame(
            ['Acme Managed Services'],
            $statusResponse->viewData('contracts')->getCollection()->pluck('client.name')->all()
        );
    }

    #[Test]
    public function package_index_can_search_filter_and_sort_packages(): void
    {
        Package::query()->create([
            'name' => 'Endpoint Care',
            'description' => 'Managed workstation bundle.',
            'status' => 'active',
            'created_by_user_id' => $this->tech->id,
        ]);

        Package::query()->create([
            'name' => 'Legacy Monitoring',
            'description' => 'Older monitoring package.',
            'status' => 'inactive',
            'created_by_user_id' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.packages.index', ['sort' => 'name', 'direction' => 'asc']))
            ->assertOk()
            ->assertSeeInOrder(['Endpoint Care', 'Legacy Monitoring']);

        $searchResponse = $this->actingAs($this->tech)
            ->get(route('tech.packages.index', ['q' => 'Endpoint']))
            ->assertOk()
            ->assertSee('Endpoint Care');

        $this->assertSame(
            ['Endpoint Care'],
            $searchResponse->viewData('packages')->getCollection()->pluck('name')->all()
        );

        $statusResponse = $this->actingAs($this->tech)
            ->get(route('tech.packages.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('Legacy Monitoring');

        $this->assertSame(
            ['Legacy Monitoring'],
            $statusResponse->viewData('packages')->getCollection()->pluck('name')->all()
        );
    }

    #[Test]
    public function service_index_can_search_filter_and_sort_services(): void
    {
        $unit = Units::query()->create(['name' => 'Month', 'short' => 'mo']);

        Services::query()->create([
            'sku' => 'ENDPOINT-CARE',
            'name' => 'Endpoint Care',
            'unitId' => $unit->id,
            'status' => 'Active',
            'availability_audience' => 'business',
            'orderable' => true,
            'taxable' => 25,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => 990,
            'price_including_tax' => 1237.50,
            'created_by_user_id' => $this->tech->id,
            'updated_by_user_id' => $this->tech->id,
        ]);

        Services::query()->create([
            'sku' => 'LEGACY-MON',
            'name' => 'Legacy Monitoring',
            'unitId' => $unit->id,
            'status' => 'Inactive',
            'availability_audience' => 'all',
            'orderable' => false,
            'taxable' => 25,
            'billing_cycle' => 'yearly',
            'price_ex_vat' => 490,
            'price_including_tax' => 612.50,
            'created_by_user_id' => $this->tech->id,
            'updated_by_user_id' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.services.index', ['sort' => 'name', 'direction' => 'asc']))
            ->assertOk()
            ->assertSeeInOrder(['Endpoint Care', 'Legacy Monitoring']);

        $searchResponse = $this->actingAs($this->tech)
            ->get(route('tech.services.index', ['q' => 'Endpoint']))
            ->assertOk()
            ->assertSee('Endpoint Care');

        $this->assertSame(
            ['Endpoint Care'],
            $searchResponse->viewData('services')->getCollection()->pluck('name')->all()
        );

        $statusResponse = $this->actingAs($this->tech)
            ->get(route('tech.services.index', ['status' => 'Inactive']))
            ->assertOk()
            ->assertSee('Legacy Monitoring');

        $this->assertSame(
            ['Legacy Monitoring'],
            $statusResponse->viewData('services')->getCollection()->pluck('name')->all()
        );

        $orderableResponse = $this->actingAs($this->tech)
            ->get(route('tech.services.index', ['orderable' => 'yes']))
            ->assertOk()
            ->assertSee('Endpoint Care');

        $this->assertSame(
            ['Endpoint Care'],
            $orderableResponse->viewData('services')->getCollection()->pluck('name')->all()
        );
    }

    #[Test]
    public function service_edit_shows_timebank_minutes_as_integer(): void
    {
        $unit = Units::query()->create(['name' => 'Hour', 'short' => 'h']);
        $service = Services::query()->create([
            'sku' => 'TIMEBANK-PLAN',
            'name' => 'Timebank plan',
            'unitId' => $unit->id,
            'status' => 'Active',
            'taxable' => 25,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => 1000,
            'price_including_tax' => 1250,
            'timebank_enabled' => true,
            'timebank_minutes' => 60,
            'timebank_interval' => 'monthly',
            'created_by_user_id' => $this->tech->id,
            'updated_by_user_id' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.services.edit', $service))
            ->assertOk()
            ->assertSee('name="timebank_minutes"', false)
            ->assertSee('value="60"', false)
            ->assertDontSee('value="60.00"', false)
            ->assertDontSee('value="60,00"', false);
    }

    #[Test]
    public function service_update_accepts_decimal_formatted_timebank_minutes(): void
    {
        $unit = Units::query()->create(['name' => 'Hour', 'short' => 'h']);
        $service = Services::query()->create([
            'sku' => 'TIMEBANK-SAVE',
            'name' => 'Timebank save',
            'unitId' => $unit->id,
            'status' => 'draft',
            'taxable' => 25,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => 1000,
            'price_including_tax' => 1250,
            'timebank_enabled' => true,
            'timebank_minutes' => 30,
            'timebank_interval' => 'monthly',
            'created_by_user_id' => $this->tech->id,
            'updated_by_user_id' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.services.update', $service), [
                'sku' => 'TIMEBANK-SAVE',
                'name' => 'Timebank save',
                'unitId' => $unit->id,
                'status' => 'draft',
                'taxable' => 25,
                'billing_cycle' => 'monthly',
                'price_ex_vat' => 1000,
                'price_including_tax' => 1250,
                'timebank_enabled' => 1,
                'timebank_minutes' => '60,00',
                'timebank_interval' => 'monthly',
            ])
            ->assertRedirect(route('tech.services.show', $service));

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'timebank_minutes' => 60,
        ]);
    }

    #[Test]
    public function cost_index_can_search_filter_and_sort_costs(): void
    {
        $unit = Units::query()->create(['name' => 'Month', 'short' => 'mo']);
        $acmeVendor = Vendor::query()->create(['name' => 'Acme Vendor']);
        $zenithVendor = Vendor::query()->create(['name' => 'Zenith Vendor']);

        Cost::query()->create([
            'name' => 'Endpoint license',
            'cost' => 120,
            'unitId' => $unit->id,
            'recurrence' => 'month',
            'vendor_id' => $acmeVendor->id,
            'note' => 'Monthly endpoint cost.',
            'created_by_user_id' => $this->tech->id,
            'updated_by_user_id' => $this->tech->id,
        ]);

        Cost::query()->create([
            'name' => 'Backup platform',
            'cost' => 500,
            'unitId' => $unit->id,
            'recurrence' => 'year',
            'vendor_id' => $zenithVendor->id,
            'note' => 'Yearly backup platform cost.',
            'created_by_user_id' => $this->tech->id,
            'updated_by_user_id' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.costs.index', ['sort' => 'name', 'direction' => 'asc']))
            ->assertOk()
            ->assertSeeInOrder(['Backup platform', 'Endpoint license']);

        $searchResponse = $this->actingAs($this->tech)
            ->get(route('tech.costs.index', ['q' => 'Endpoint']))
            ->assertOk()
            ->assertSee('Endpoint license');

        $this->assertSame(
            ['Endpoint license'],
            $searchResponse->viewData('costs')->getCollection()->pluck('name')->all()
        );

        $vendorResponse = $this->actingAs($this->tech)
            ->get(route('tech.costs.index', ['vendor_id' => $zenithVendor->id]))
            ->assertOk()
            ->assertSee('Backup platform');

        $this->assertSame(
            ['Backup platform'],
            $vendorResponse->viewData('costs')->getCollection()->pluck('name')->all()
        );

        $recurrenceResponse = $this->actingAs($this->tech)
            ->get(route('tech.costs.index', ['recurrence' => 'month']))
            ->assertOk()
            ->assertSee('Endpoint license');

        $this->assertSame(
            ['Endpoint license'],
            $recurrenceResponse->viewData('costs')->getCollection()->pluck('name')->all()
        );
    }

    #[Test]
    public function cost_form_links_to_vendor_creation(): void
    {
        Units::query()->create(['name' => 'Month', 'short' => 'mo']);

        $this->actingAs($this->tech)
            ->get(route('tech.costs.create'))
            ->assertOk()
            ->assertViewIs('commercial::Tech.cs.costs.form')
            ->assertSee('Vendor')
            ->assertSee('New vendor')
            ->assertSee(route('tech.documentations.vendors.create'), false)
            ->assertSee('target="_blank"', false);
    }

    #[Test]
    public function cost_can_be_created_without_note(): void
    {
        $unit = Units::query()->create(['name' => 'Lisens', 'short' => 'lic']);
        $vendor = Vendor::query()->create(['name' => 'Microsoft']);

        $this->actingAs($this->tech)
            ->post(route('tech.costs.store'), [
                'name' => 'Exchange Online (Plan1)',
                'cost' => 48,
                'unitId' => $unit->id,
                'recurrence' => 'month',
                'vendor_id' => $vendor->id,
            ])
            ->assertRedirect(route('tech.costs.index'));

        $this->assertDatabaseHas('costs', [
            'name' => 'Exchange Online (Plan1)',
            'cost' => 48,
            'unitId' => $unit->id,
            'vendor_id' => $vendor->id,
            'note' => '',
        ]);
    }

    #[Test]
    public function admin_commercial_units_route_is_owned_by_commercial_module(): void
    {
        $this->assertSame(
            UnitsController::class . '@index',
            Route::getRoutes()->getByName('tech.admin.settings.economy.units')->getActionName()
        );

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.economy.units'))
            ->assertOk()
            ->assertViewIs('commercial::Admin.economy.units.index');
    }

    #[Test]
    public function admin_creates_units_with_post_only_and_updates_common_code(): void
    {
        $this->assertContains('POST', Route::getRoutes()->getByName('tech.admin.settings.economy.units.store')->methods());

        $this->actingAs($this->admin)
            ->get('/tech/admin/settings/economy/units/store')
            ->assertNotFound();

        $this->assertDatabaseCount('units', 0);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.economy.units.store'))
            ->assertRedirect(route('tech.admin.settings.economy.units'));

        $unit = Units::query()->firstOrFail();

        $this->assertSame('xxx', $unit->name);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.economy.units.update', $unit), [
                'action' => 'update',
                'name' => 'Hour',
                'short' => 'h',
                'common_code' => 'HUR',
            ])
            ->assertRedirect(route('tech.admin.settings.economy.units'));

        $unit->refresh();

        $this->assertSame('Hour', $unit->name);
        $this->assertSame('h', $unit->short);
        $this->assertSame('HUR', $unit->common_code);
    }

    #[Test]
    public function cost_deletion_is_delete_only(): void
    {
        $unit = Units::query()->create([
            'name' => 'Hour',
            'short' => 'h',
            'common_code' => 'HUR',
        ]);
        $vendor = Vendor::query()->create([
            'name' => 'Vendor AS',
        ]);
        $cost = Cost::query()->create([
            'name' => 'Endpoint license',
            'cost' => 120,
            'unitId' => $unit->id,
            'recurrence' => 'month',
            'vendor_id' => $vendor->id,
            'note' => 'Monthly endpoint cost.',
            'created_by_user_id' => $this->tech->id,
            'updated_by_user_id' => $this->tech->id,
        ]);

        $this->assertContains('DELETE', Route::getRoutes()->getByName('tech.costs.delete')->methods());

        $this->actingAs($this->tech)
            ->get('/tech/costs/delete/'.$cost->id)
            ->assertStatus(405);

        $this->assertDatabaseHas('costs', ['id' => $cost->id]);

        $this->actingAs($this->tech)
            ->delete(route('tech.costs.delete', $cost))
            ->assertRedirect(route('tech.costs.index'));

        $this->assertDatabaseMissing('costs', ['id' => $cost->id]);
    }

    #[Test]
    public function tech_user_can_manage_time_rates_from_sales_workspace(): void
    {
        $this->assertSame(
            TimeRateController::class . '@index',
            Route::getRoutes()->getByName('tech.rates.index')->getActionName()
        );

        $this->actingAs($this->tech)
            ->get(route('tech.rates.index'))
            ->assertOk()
            ->assertViewIs('commercial::Tech.cs.rates.index')
            ->assertSee('New Rate')
            ->assertSee('Sales workspace')
            ->assertSee('Time without contract')
            ->assertSee('Time with contract')
            ->assertSee('Driving');

        $this->actingAs($this->tech)
            ->post(route('tech.rates.store'), [
                'name' => 'Emergency work',
                'code' => 'EMERGENCY_WORK',
                'rate_type' => 'labor',
                'unit' => 'hour',
                'amount_ex_vat' => 1800,
                'currency' => 'NOK',
                'applies_with_contract' => '1',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('time_rates', [
            'code' => 'EMERGENCY_WORK',
            'amount_ex_vat' => 1800,
        ]);

        $rate = TimeRate::query()->where('code', 'EMERGENCY_WORK')->firstOrFail();

        $this->actingAs($this->tech)
            ->put(route('tech.rates.update', $rate), [
                'name' => 'Emergency work adjusted',
                'code' => 'EMERGENCY_WORK',
                'rate_type' => 'labor',
                'unit' => 'hour',
                'amount_ex_vat' => 1900,
                'currency' => 'NOK',
                'applies_with_contract' => '1',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('time_rates', [
            'code' => 'EMERGENCY_WORK',
            'name' => 'Emergency work adjusted',
            'amount_ex_vat' => 1900,
        ]);
    }

    #[Test]
    public function service_can_define_default_time_rates(): void
    {
        $unit = Units::query()->create(['name' => 'Month', 'short' => 'mo']);
        $sla = $this->createSla('Managed service SLA');
        $contractRate = TimeRate::query()->where('code', 'TIME_WITH_CONTRACT')->firstOrFail();

        $this->actingAs($this->tech)
            ->post(route('tech.services.store'), [
                'sku' => 'MSP-BASE',
                'name' => 'Managed service',
                'unitId' => $unit->id,
                'sla_id' => $sla->id,
                'status' => 'published',
                'orderable' => '1',
                'taxable' => 25,
                'billing_cycle' => 'monthly',
                'price_ex_vat' => 1000,
                'price_including_tax' => 1250,
                'time_rates' => [
                    $contractRate->id => [
                        'enabled' => '1',
                        'amount_ex_vat' => 600,
                    ],
                ],
            ])
            ->assertRedirect(route('tech.services.index'));

        $service = Services::query()->where('sku', 'MSP-BASE')->firstOrFail();

        $this->assertSame($sla->id, $service->sla_id);

        $this->assertDatabaseHas('service_time_rates', [
            'service_id' => $service->id,
            'time_rate_id' => $contractRate->id,
            'amount_ex_vat' => 600,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function service_legal_component_persists_terms_when_editing_service(): void
    {
        $unit = Units::query()->create(['name' => 'Month', 'short' => 'mo']);
        $service = Services::query()->create([
            'sku' => 'MSP-LEGAL',
            'name' => 'Managed legal service',
            'unitId' => $unit->id,
            'status' => 'published',
            'orderable' => true,
            'taxable' => 25,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => 1000,
            'price_including_tax' => 1250,
            'created_by_user_id' => $this->tech->id,
            'updated_by_user_id' => $this->tech->id,
        ]);
        $term = CommercialTerm::query()->create([
            'name' => 'Service DPA',
            'type' => 'dpa',
            'content' => 'Service data processing terms.',
        ]);

        Livewire::actingAs($this->tech)
            ->test(ServiceLegal::class, ['service' => $service, 'enabled' => 'enabled'])
            ->call('toggleTerm', $term->id);

        $this->assertDatabaseHas('service_term_pivot', [
            'service_id' => $service->id,
            'term_id' => $term->id,
        ]);

        Livewire::actingAs($this->tech)
            ->test(ServiceLegal::class, ['service' => $service->fresh(), 'enabled' => 'enabled'])
            ->call('removeTerm', $term->id);

        $this->assertDatabaseMissing('service_term_pivot', [
            'service_id' => $service->id,
            'term_id' => $term->id,
        ]);
    }

    #[Test]
    public function contract_item_snapshots_service_time_rates(): void
    {
        $client = Client::factory()->create();
        $unit = Units::query()->create(['name' => 'Month', 'short' => 'mo']);
        $contractSla = $this->createSla('Contract default SLA', true);
        $serviceSla = $this->createSla('Third-party email SLA');
        $service = Services::query()->create([
            'sku' => 'MSP-SNAPSHOT',
            'name' => 'Managed service',
            'unitId' => $unit->id,
            'sla_id' => $serviceSla->id,
            'status' => 'published',
            'orderable' => true,
            'taxable' => 25,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => 1000,
            'price_including_tax' => 1250,
            'created_by_user_id' => $this->tech->id,
            'updated_by_user_id' => $this->tech->id,
        ]);
        $rate = TimeRate::query()->where('code', 'TIME_WITH_CONTRACT')->firstOrFail();
        $service->serviceTimeRates()->create([
            'time_rate_id' => $rate->id,
            'amount_ex_vat' => 575,
            'is_active' => true,
        ]);
        $terms = CommercialTerm::query()->create([
            'name' => 'Managed terms',
            'type' => 'terms',
            'content' => 'General managed service terms.',
        ]);
        $dpa = CommercialTerm::query()->create([
            'name' => 'Managed DPA',
            'type' => 'dpa',
            'content' => 'Data processing agreement content.',
        ]);
        $legal = CommercialTerm::query()->create([
            'name' => 'Managed legal',
            'type' => 'legal',
            'content' => 'Legal and GDPR content.',
        ]);
        $service->serviceTerms()->attach([$terms->id, $dpa->id, $legal->id]);
        $contract = Contracts::query()->create([
            'client_id' => $client->id,
            'sla_id' => $contractSla->id,
            'description' => 'Contract with rates.',
            'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'binding_end_date' => now()->addYear()->toDateString(),
            'auto_renew' => true,
            'renewal_months' => 12,
            'approval_status' => 'draft',
            'created_by' => $this->tech->id,
        ]);

        Livewire::actingAs($this->tech)
            ->test(ContractItemsEditor::class, ['contract' => $contract])
            ->call('addItem')
            ->set('items.0.service_id', $service->id)
            ->set('items.0.time_rates.0.amount_ex_vat', 550)
            ->call('saveItem', 0);

        $item = $contract->items()->firstOrFail();
        $contract->refresh();

        $this->assertFalse($item->uses_contract_default_sla);
        $this->assertSame($serviceSla->id, $item->sla_id);
        $this->assertSame('Third-party email SLA', $item->sla_snapshot['name']);
        $this->assertSame("Managed terms\nGeneral managed service terms.", $contract->terms_snapshot);
        $this->assertSame("Managed DPA\nData processing agreement content.", $contract->dpa_snapshot);
        $this->assertSame("Managed legal\nLegal and GDPR content.", $contract->legal_snapshot);
        $this->assertStringContainsString('Contract default SLA: Contract default SLA', $contract->sla_snapshot);
        $this->assertStringContainsString('Service SLA: Third-party email SLA', $contract->sla_snapshot);

        $this->assertDatabaseHas('contract_item_time_rates', [
            'contract_item_id' => $item->id,
            'time_rate_id' => $rate->id,
            'amount_ex_vat' => 550,
            'name' => 'Time with contract',
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.contracts.show', $contract))
            ->assertOk()
            ->assertSee('Third-party email SLA')
            ->assertSee('Time with contract')
            ->assertSee('550,00');
    }

    #[Test]
    public function approved_contract_show_does_not_render_not_ready_alert(): void
    {
        $client = Client::factory()->create();
        $contract = Contracts::query()->create([
            'client_id' => $client->id,
            'description' => 'Approved contract with historical start date.',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'binding_end_date' => now()->addYear()->toDateString(),
            'auto_renew' => true,
            'renewal_months' => 12,
            'approval_status' => 'won',
            'accepted_at' => now(),
            'accepted_by_name' => 'Internal Approval',
            'created_by' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.contracts.show', $contract))
            ->assertOk()
            ->assertSee('Contract Won')
            ->assertDontSee('Contract not ready for approval')
            ->assertDontSee('Start date must be in the future.');
    }

    #[Test]
    public function contract_services_back_button_returns_to_contract_show(): void
    {
        $client = Client::factory()->create();
        $contract = Contracts::query()->create([
            'client_id' => $client->id,
            'description' => 'Contract service edit navigation.',
            'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'binding_end_date' => now()->addYear()->toDateString(),
            'auto_renew' => true,
            'renewal_months' => 12,
            'approval_status' => 'draft',
            'created_by' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.contracts.services.edit', $contract))
            ->assertOk()
            ->assertSee('Contract #'.$contract->id.' Services')
            ->assertSee('href="'.route('tech.contracts.show', $contract).'"', false);
    }

    #[Test]
    public function contract_item_service_selection_populates_unit_price_field(): void
    {
        $client = Client::factory()->create();
        $unit = Units::query()->create(['name' => 'License', 'short' => 'lic']);
        $service = Services::query()->create([
            'sku' => 'EXCHANGE-P1',
            'name' => 'Exchange Online (Plan1)',
            'unitId' => $unit->id,
            'status' => 'published',
            'orderable' => true,
            'taxable' => 0,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => 48,
            'price_including_tax' => 48,
            'created_by_user_id' => $this->tech->id,
            'updated_by_user_id' => $this->tech->id,
        ]);
        $contract = Contracts::query()->create([
            'client_id' => $client->id,
            'description' => 'Contract with auto priced line.',
            'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'binding_end_date' => now()->addYear()->toDateString(),
            'auto_renew' => true,
            'renewal_months' => 12,
            'approval_status' => 'draft',
            'created_by' => $this->tech->id,
        ]);

        Livewire::actingAs($this->tech)
            ->test(ContractItemsEditor::class, ['contract' => $contract])
            ->call('addItem')
            ->set('items.0.service_id', $service->id)
            ->assertSet('items.0.unit_price', '48.00')
            ->assertSee('48,00 kr');

        $this->assertDatabaseHas('contract_items', [
            'contract_id' => $contract->id,
            'service_id' => $service->id,
            'unit_price' => 48,
        ]);
    }

    #[Test]
    public function contract_item_uses_contract_default_sla_when_service_has_no_sla(): void
    {
        $client = Client::factory()->create();
        $unit = Units::query()->create(['name' => 'Month', 'short' => 'mo']);
        $contractSla = $this->createSla('Contract fallback SLA', true);
        $service = Services::query()->create([
            'sku' => 'MSP-FALLBACK',
            'name' => 'Fallback service',
            'unitId' => $unit->id,
            'status' => 'published',
            'orderable' => true,
            'taxable' => 25,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => 1000,
            'price_including_tax' => 1250,
            'created_by_user_id' => $this->tech->id,
            'updated_by_user_id' => $this->tech->id,
        ]);
        $contract = Contracts::query()->create([
            'client_id' => $client->id,
            'sla_id' => $contractSla->id,
            'description' => 'Contract with fallback SLA.',
            'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'binding_end_date' => now()->addYear()->toDateString(),
            'auto_renew' => true,
            'renewal_months' => 12,
            'approval_status' => 'draft',
            'created_by' => $this->tech->id,
        ]);

        Livewire::actingAs($this->tech)
            ->test(ContractItemsEditor::class, ['contract' => $contract])
            ->call('addItem')
            ->set('items.0.service_id', $service->id)
            ->call('saveItem', 0);

        $item = $contract->items()->firstOrFail();

        $this->assertTrue($item->uses_contract_default_sla);
        $this->assertNull($item->sla_id);
        $this->assertNull($item->sla_snapshot);
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
    public function contract_pdf_route_is_owned_by_commercial_module(): void
    {
        $this->assertSame(
            ContractController::class . '@pdf',
            Route::getRoutes()->getByName('tech.contracts.pdf')->getActionName()
        );
    }

    #[Test]
    public function tech_user_can_download_contract_pdf_from_contract_show(): void
    {
        $client = Client::factory()->create(['name' => 'PDF Client']);
        $contract = Contracts::query()->create([
            'client_id' => $client->id,
            'description' => 'PDF ready contract.',
            'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'binding_end_date' => now()->addYear()->toDateString(),
            'auto_renew' => true,
            'renewal_months' => 12,
            'approval_status' => 'draft',
            'terms_snapshot' => 'PDF terms and conditions.',
            'created_by' => $this->tech->id,
        ]);
        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'name' => 'Managed support',
            'sku' => 'SUPPORT',
            'unit_price' => 1500,
            'quantity' => 2,
            'unit' => 'month',
            'billing_interval' => 'monthly',
            'uses_contract_default_sla' => true,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.contracts.show', $contract))
            ->assertOk()
            ->assertSee(route('tech.contracts.pdf', $contract), false);

        $response = $this->actingAs($this->tech)
            ->get(route('tech.contracts.pdf', $contract));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString(
            'attachment; filename="contract-'.$contract->id.'-pdf-client.pdf"',
            $response->headers->get('content-disposition')
        );
    }

    #[Test]
    public function public_contract_uses_company_profile_brand_name(): void
    {
        app(CompanyProfileSettings::class)->update([
            'company_name' => 'Tronder Data',
        ]);

        $client = Client::factory()->create(['name' => 'Brand Client']);
        $contract = Contracts::query()->create([
            'client_id' => $client->id,
            'description' => 'Brand-aware public contract.',
            'start_date' => now()->toDateString(),
            'approval_status' => 'sent_quote',
            'secure_token' => 'public-brand-token',
            'created_by' => $this->tech->id,
        ]);

        $this->get(route('contracts.public.view', $contract->secure_token))
            ->assertOk()
            ->assertSee('Tronder Data')
            ->assertDontSee('tdPSA');
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

    private function createSla(string $name, bool $isDefault = false): Sla
    {
        return Sla::create([
            'name' => $name,
            'description' => 'SLA used by commercial tests.',
            'is_default' => $isDefault,
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
    }

    #[Test]
    public function contract_form_can_store_structured_sla_policy(): void
    {
        $client = Client::factory()->create();
        $sla = Sla::create([
            'name' => 'Contract support',
            'description' => 'Contract response policy.',
            'is_default' => false,
            'low_firstResponse' => 24,
            'low_firstResponse_type' => 'hours',
            'low_onsite' => 48,
            'low_onsite_type' => 'hours',
            'medium_firstResponse' => 12,
            'medium_firstResponse_type' => 'hours',
            'medium_onsite' => 24,
            'medium_onsite_type' => 'hours',
            'high_firstResponse' => 6,
            'high_firstResponse_type' => 'hours',
            'high_onsite' => 12,
            'high_onsite_type' => 'hours',
            'created_by_user_id' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.contracts.create'))
            ->assertOk()
            ->assertSee('SLA Policy')
            ->assertSee('Use current system default SLA')
            ->assertSee('Contract support')
            ->assertDontSee('View Specification')
            ->assertDontSee('Status: Not completed');

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.store'), [
                'client_id' => $client->id,
                'sla_id' => $sla->id,
                'created_by' => $this->tech->id,
                'description' => 'Contract with structured SLA.',
                'start_date' => now()->addMonth()->startOfMonth()->toDateString(),
                'end_date' => now()->addMonth()->startOfMonth()->addYear()->toDateString(),
                'binding_end_date' => now()->addMonth()->startOfMonth()->addYear()->toDateString(),
                'auto_renew' => '1',
                'renewal_months' => 12,
                'allow_indexing_during_binding' => '1',
                'allow_decrease_during_binding' => '0',
                'max_index_pct_binding' => '3.5',
                'post_binding_index_pct' => '10',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('contracts', [
            'client_id' => $client->id,
            'sla_id' => $sla->id,
            'approval_status' => 'draft',
        ]);

        $contract = Contracts::firstOrFail();

        $this->actingAs($this->tech)
            ->get(route('tech.contracts.show', $contract))
            ->assertOk()
            ->assertSee('Contract support')
            ->assertSee('SLA Summary')
            ->assertSee('First response 24 hours');
    }
}
