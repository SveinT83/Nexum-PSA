<?php

namespace App\Modules\Commercial\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Commercial\Models\Cost;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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
    public function contract_index_can_search_filter_and_sort_contracts(): void
    {
        $acme = Client::factory()->create(['name' => 'Acme Managed Services']);
        $zenith = Client::factory()->create(['name' => 'Zenith Operations']);

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
            ->assertSee('Acme Managed Services');

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
            ->assertSee('Contract support');

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
            ->assertSee('Contract support');
    }
}
