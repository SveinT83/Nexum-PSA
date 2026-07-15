<?php

namespace App\Modules\DataExchange\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\DataExchange\Actions\CommitDataExchangeImportPreview;
use App\Modules\DataExchange\Actions\DeliverDataExchangeFile;
use App\Modules\DataExchange\Actions\EnsureDataExchangeProfileTemplates;
use App\Modules\DataExchange\Actions\RunDataExchangeExport;
use App\Modules\DataExchange\Actions\RunDataExchangeImportDryRun;
use App\Modules\DataExchange\Livewire\Admin\ProfileBuilder;
use App\Modules\DataExchange\Models\DataExchangeDeliveryAttempt;
use App\Modules\DataExchange\Models\DataExchangeDeliveryTarget;
use App\Modules\DataExchange\Models\DataExchangeFile;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Models\DataExchangeSchedule;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Models\EconomyOrderLine;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DataExchangeRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $role = Role::query()->create(['name' => 'Data Exchange Runtime Admin', 'guard_name' => 'web']);
        $role->givePermissionTo([
            'system.view',
            'client.view',
            'economy.view',
            'economy.order_manage',
            'data_exchange.view',
            'data_exchange.manage',
            'data_exchange.run',
            'data_exchange.download',
            'data_exchange.import',
            'data_exchange.approve_import',
            'data_exchange.schedule',
            'data_exchange.delivery',
        ]);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole($role);
    }

    #[Test]
    public function livewire_builder_saves_safe_fields_filters_and_mappings(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ProfileBuilder::class)
            ->set('name', 'Client export')
            ->set('key', 'client_export')
            ->set('direction', DataExchangeProfile::DIRECTION_EXPORT)
            ->set('format', 'csv')
            ->set('status', DataExchangeProfile::STATUS_ACTIVE)
            ->set('sourceKey', 'clients')
            ->set('selectedFields', ['client_number', 'name', 'billing_email'])
            ->set('fieldOutputKeys.client_number', 'ClientNo')
            ->set('fieldOutputKeys.name', 'ClientName')
            ->set('filters', [['field_key' => 'active', 'operator' => 'equals', 'value' => '1', 'active' => true]])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('data_exchange_profiles', ['key' => 'client_export', 'status' => 'active']);
        $this->assertDatabaseHas('data_exchange_profile_fields', ['field_key' => 'billing_email']);
        $this->assertDatabaseHas('data_exchange_profile_filters', ['field_key' => 'active', 'operator' => 'equals']);
        $this->assertDatabaseMissing('data_exchange_profile_fields', ['field_key' => 'password']);
    }

    #[Test]
    public function export_runtime_generates_csv_json_and_xlsx_files(): void
    {
        Storage::fake('local');
        Client::factory()->create(['name' => 'Runtime Client AS', 'billing_email' => 'billing@example.test']);
        $templates = app(EnsureDataExchangeProfileTemplates::class);
        $profile = $templates->clientsBasicImportProfile($this->admin);
        $profile->forceFill(['direction' => DataExchangeProfile::DIRECTION_EXPORT])->save();

        foreach (['csv', 'json', 'xlsx'] as $format) {
            $profile->forceFill(['format' => $format])->save();

            $run = app(RunDataExchangeExport::class)->handle($profile, $this->admin);
            $file = $run->files()->firstOrFail();

            Storage::disk($file->disk)->assertExists($file->path);
            $this->assertSame($format, $file->format);
            $this->assertSame('succeeded', $run->status);
        }
    }

    #[Test]
    public function import_preview_commits_clients_only_through_registered_target(): void
    {
        $profile = app(EnsureDataExchangeProfileTemplates::class)->clientsBasicImportProfile($this->admin);
        $file = UploadedFile::fake()->createWithContent(
            'clients.csv',
            "client_number,name,billing_email,site_name,contact_name,contact_email\nC-100,Imported Client AS,billing@client.test,Main,Import Contact,contact@client.test\n",
        );

        $preview = app(RunDataExchangeImportDryRun::class)->handle($profile, $file, $this->admin);
        $this->assertSame(1, $preview->valid_count);
        $this->assertSame(0, $preview->invalid_count);

        app(CommitDataExchangeImportPreview::class)->handle($preview, $this->admin);

        $this->assertDatabaseHas('clients', [
            'client_number' => 'C-100',
            'name' => 'Imported Client AS',
            'billing_email' => 'billing@client.test',
        ]);
    }

    #[Test]
    public function schedule_delivery_attempt_copies_export_to_configured_disk(): void
    {
        Storage::fake('local');
        Config::set('filesystems.disks.dx-target', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/dx-target')]);
        Storage::fake('dx-target');
        Client::factory()->create(['name' => 'Delivery Client AS']);
        $profile = app(EnsureDataExchangeProfileTemplates::class)->clientsBasicImportProfile($this->admin);
        $profile->forceFill(['direction' => DataExchangeProfile::DIRECTION_EXPORT, 'format' => 'csv'])->save();
        $run = app(RunDataExchangeExport::class)->handle($profile, $this->admin);
        $file = $run->files()->firstOrFail();
        $target = DataExchangeDeliveryTarget::query()->create([
            'name' => 'Accounting SFTP',
            'type' => 'sftp',
            'direction' => 'export',
            'filesystem_disk' => 'dx-target',
            'remote_path' => 'exports',
            'active' => true,
        ]);
        $schedule = DataExchangeSchedule::query()->create([
            'profile_id' => $profile->id,
            'delivery_target_id' => $target->id,
            'direction' => 'export',
            'active' => true,
            'frequency' => 'daily',
        ]);

        $attempt = app(DeliverDataExchangeFile::class)->handle($file, $target, $schedule);

        $this->assertSame(DataExchangeDeliveryAttempt::STATUS_SUCCEEDED, $attempt->status);
        Storage::disk('dx-target')->assertExists('exports/'.$file->filename);
    }

    #[Test]
    public function api_clients_can_list_trigger_status_and_download_with_data_exchange_abilities(): void
    {
        Storage::fake('local');
        Client::factory()->create(['name' => 'API Client AS']);
        $profile = app(EnsureDataExchangeProfileTemplates::class)->clientsBasicImportProfile($this->admin);
        $profile->forceFill(['direction' => DataExchangeProfile::DIRECTION_EXPORT, 'format' => 'csv'])->save();

        Sanctum::actingAs($this->admin, ['data_exchange.read', 'data_exchange.run', 'data_exchange.download']);

        $this->getJson('/api/v1/data-exchange/profiles')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'clients_basic_import');

        $runId = $this->postJson('/api/v1/data-exchange/profiles/'.$profile->id.'/runs')
            ->assertCreated()
            ->json('data.id');

        $this->getJson('/api/v1/data-exchange/runs/'.$runId)
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded');

        $file = DataExchangeFile::query()->firstOrFail();
        $this->get('/api/v1/data-exchange/files/'.$file->id.'/download')
            ->assertOk();
    }

    #[Test]
    public function economy_orders_export_uses_data_exchange_profile_and_stored_file(): void
    {
        Storage::fake('local');
        $client = Client::factory()->create(['name' => 'Economy Export AS', 'client_number' => 'E-100']);
        $order = EconomyOrder::query()->create([
            'order_number' => 'ORD-100',
            'client_id' => $client->id,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'status' => 'ready',
            'subtotal_ex_vat' => 1000,
            'vat_amount' => 250,
            'total_inc_vat' => 1250,
        ]);
        EconomyOrderLine::query()->create([
            'economy_order_id' => $order->id,
            'client_id' => $client->id,
            'line_type' => 'manual',
            'description' => 'Managed services',
            'quantity' => 1,
            'unit' => 'month',
            'unit_price_ex_vat' => 1000,
            'line_total_ex_vat' => 1000,
            'vat_rate' => 25,
            'vat_amount' => 250,
            'total_inc_vat' => 1250,
            'currency' => 'NOK',
            'status' => 'draft',
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.economy.orders.export'))
            ->assertRedirect();

        $file = DataExchangeFile::query()->firstOrFail();
        Storage::disk('local')->assertExists($file->path);
        $this->assertStringContainsString('Managed services', Storage::disk('local')->get($file->path));
        $this->assertDatabaseHas('data_exchange_profiles', ['key' => 'economy_orders_export']);
    }
}
