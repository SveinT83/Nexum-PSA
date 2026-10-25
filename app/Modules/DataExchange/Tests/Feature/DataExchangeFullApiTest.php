<?php

namespace App\Modules\DataExchange\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\DataExchange\Actions\PlanDueDataExchangeSchedules;
use App\Modules\DataExchange\Actions\RunDataExchangeExport;
use App\Modules\DataExchange\Jobs\RunDataExchangeScheduleJob;
use App\Modules\DataExchange\Models\DataExchangeCommandReceipt;
use App\Modules\DataExchange\Models\DataExchangeDeliveryAttempt;
use App\Modules\DataExchange\Models\DataExchangeDeliveryTarget;
use App\Modules\DataExchange\Models\DataExchangeFile;
use App\Modules\DataExchange\Models\DataExchangeImportPreview;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Models\DataExchangeRun;
use App\Modules\DataExchange\Models\DataExchangeSchedule;
use App\Modules\DataExchange\Models\DataExchangeScheduleOccurrence;
use App\Modules\DataExchange\Support\DataExchangeRevision;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Exercises real HTTP/domain persistence and side effects rather than mirroring controller branches. */
class DataExchangeFullApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->role = Role::create(['name' => 'Exchange operator', 'guard_name' => 'web']);
        $this->role->givePermissionTo(['system.view', 'client.view', 'economy.view', 'data_exchange.view', 'data_exchange.manage', 'data_exchange.run', 'data_exchange.download', 'data_exchange.import', 'data_exchange.approve_import', 'data_exchange.schedule', 'data_exchange.delivery']);
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole($this->role);
        $this->login();
        Storage::fake('local');
        Config::set('filesystems.disks.exchange-out', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/exchange-out')]);
        Storage::fake('exchange-out');
        Config::set('data_exchange.delivery_disks', ['exchange-out']);
    }

    private function login(array $abilities = ['*']): void
    {
        Sanctum::actingAs($this->actor, $abilities);
    }

    private function body(array $replace = []): array
    {
        return array_replace([
            'name' => 'Safe clients', 'key' => 'safe_clients', 'direction' => 'export', 'format' => 'csv', 'status' => 'active', 'description' => 'Safe exchange',
            'source_key' => 'clients', 'fields' => [['field_key' => 'client_number', 'output_key' => 'Number', 'label' => 'Client number'], ['field_key' => 'name', 'output_key' => 'Name', 'label' => 'Name']],
            'filters' => [],
        ], $replace);
    }

    private function profile(array $replace = []): DataExchangeProfile
    {
        $id = $this->withHeaders(['Idempotency-Key' => 'profile-'.$this->randomKey()])->postJson('/api/v1/data-exchange/profiles', $this->body($replace))->assertCreated()->json('data.id');

        return DataExchangeProfile::findOrFail($id);
    }

    private function randomKey(): string
    {
        return bin2hex(random_bytes(10));
    }

    private function file(DataExchangeProfile $profile): DataExchangeFile
    {
        return app(RunDataExchangeExport::class)->handle($profile, $this->actor)->files()->firstOrFail();
    }

    private function target(DataExchangeProfile $profile): DataExchangeDeliveryTarget
    {
        $id = $this->withHeaders(['Idempotency-Key' => 'target-'.$this->randomKey()])->postJson('/api/v1/data-exchange/delivery-targets', [
            'profile_id' => $profile->id, 'name' => 'Approved local transfer', 'type' => 'local', 'direction' => 'export', 'filesystem_disk' => 'exchange-out', 'remote_path' => 'exports', 'active' => true,
        ])->assertCreated()->json('data.id');

        return DataExchangeDeliveryTarget::findOrFail($id);
    }

    #[Test]
    public function discovery_is_pure_and_describes_safe_sources_and_unavailable_pickup(): void
    {
        $this->getJson('/api/v1/data-exchange/context')->assertOk()->assertJsonPath('data.capabilities.automatic_import_pickup', false)->assertJsonPath('data.capabilities.api_delivery_enabled', false)->assertJsonPath('data.counts.profiles', 0);
        $this->getJson('/api/v1/data-exchange/sources/clients')->assertOk()->assertJsonPath('data.key', 'clients');
        $this->assertSame(0, DataExchangeProfile::count());
        $this->assertSame(0, DataExchangeRun::count());
        $this->getJson('/api/v1/data-exchange/sources/missing')->assertNotFound();
        $this->getJson('/api/v1/data-exchange/context?sql=SELECT')->assertUnprocessable();
    }

    #[Test]
    public function profiles_compose_atomically_read_back_edit_pause_and_replay(): void
    {
        $response = $this->withHeaders(['Idempotency-Key' => 'compose-profile-1'])->postJson('/api/v1/data-exchange/profiles', $this->body())->assertCreated();
        $id = $response->json('data.id');
        $this->withHeaders(['Idempotency-Key' => 'compose-profile-1'])->postJson('/api/v1/data-exchange/profiles', $this->body())->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $id);
        $this->assertSame(1, DataExchangeProfile::count());
        $detail = $this->getJson('/api/v1/data-exchange/profiles/'.$id)->assertOk()->assertJsonPath('data.fields.0.output_key', 'Number');
        $revision = $detail->json('data.revision');
        $this->withHeaders(['Idempotency-Key' => 'edit-profile-001', 'If-Match' => $revision])->patchJson('/api/v1/data-exchange/profiles/'.$id, ['status' => 'paused', 'description' => null])->assertOk()->assertJsonPath('data.status', 'paused')->assertJsonPath('data.description', null);
        $this->withHeaders(['Idempotency-Key' => 'stale-profile-01', 'If-Match' => $revision])->patchJson('/api/v1/data-exchange/profiles/'.$id, ['name' => 'Lost update'])->assertConflict();
        $this->assertDatabaseHas('data_exchange_profiles', ['id' => $id, 'name' => 'Safe clients', 'status' => 'paused']);
        $this->getJson('/api/v1/data-exchange/profiles?per_page=1')->assertOk()->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function unsafe_fields_filters_mapping_keys_and_unknown_input_leave_no_profile(): void
    {
        foreach ([
            ['fields' => [['field_key' => 'password', 'output_key' => 'password']]],
            ['filters' => [['field_key' => 'name', 'operator' => 'SQL', 'value' => 'x', 'active' => true]]],
            ['fields' => [['field_key' => 'name', 'output_key' => 'x', 'secret' => 'no']]],
            ['fields' => [['field_key' => 'name', 'output_key' => 'x'], ['field_key' => 'client_number', 'output_key' => 'x']]],
            ['raw_sql' => 'SELECT * FROM clients'],
        ] as $replace) {
            $this->withHeaders(['Idempotency-Key' => 'invalid-'.$this->randomKey()])->postJson('/api/v1/data-exchange/profiles', $this->body($replace))->assertUnprocessable();
        }
        $this->assertSame(0, DataExchangeProfile::count());
        $this->assertSame(0, DataExchangeCommandReceipt::count());
    }

    #[Test]
    public function current_permissions_source_rights_and_token_scopes_are_independent(): void
    {
        $p = $this->profile();
        $this->login(['data_exchange.read']);
        $this->withHeaders(['Idempotency-Key' => 'denied-scope-001'])->postJson('/api/v1/data-exchange/profiles', $this->body(['key' => 'second']))->assertForbidden();
        $this->role->revokePermissionTo('client.view');
        $this->getJson('/api/v1/data-exchange/profiles')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/data-exchange/profiles/'.$p->id)->assertForbidden();
        $this->role->givePermissionTo('client.view');
        $this->actor->forceFill(['status' => 'inactive'])->save();
        $this->getJson('/api/v1/data-exchange/context')->assertForbidden();
    }

    #[Test]
    public function profile_mutations_require_key_and_revision_and_replay_rechecks_current_authority(): void
    {
        $this->postJson('/api/v1/data-exchange/profiles', $this->body())->assertUnprocessable();
        $p = $this->profile();
        $this->withHeaders(['Idempotency-Key' => 'need-revision-01'])->patchJson('/api/v1/data-exchange/profiles/'.$p->id, ['name' => 'Changed'])->assertStatus(428);
        $this->role->revokePermissionTo('data_exchange.manage');
        $this->withHeaders(['Idempotency-Key' => 'need-revision-01', 'If-Match' => DataExchangeRevision::of($p)])->patchJson('/api/v1/data-exchange/profiles/'.$p->id, ['name' => 'Changed'])->assertForbidden();
    }

    #[Test]
    public function export_preview_binds_data_and_commands_replay_exact_file_and_checksum(): void
    {
        $p = $this->profile();
        Client::factory()->create(['name' => 'Before']);
        $url = '/api/v1/data-exchange/profiles/'.$p->id.'/runs';
        $hash = $this->getJson($url.'/preview')->assertOk()->json('data.preview_hash');
        Client::factory()->create(['name' => 'After']);
        $this->withHeaders(['Idempotency-Key' => 'stale-export-001'])->postJson($url, ['preview_hash' => $hash])->assertConflict();
        $hash = $this->getJson($url.'/preview')->assertOk()->assertJsonPath('data.row_count', 2)->json('data.preview_hash');
        $response = $this->withHeaders(['Idempotency-Key' => 'export-command-1'])->postJson($url, ['preview_hash' => $hash])->assertCreated();
        $id = $response->json('data.id');
        $this->withHeaders(['Idempotency-Key' => 'export-command-1'])->postJson($url, ['preview_hash' => $hash])->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $id);
        $this->assertSame(1, DataExchangeRun::count());
        $file = DataExchangeFile::firstOrFail();
        $this->getJson('/api/v1/data-exchange/runs/'.$id)->assertOk()->assertJsonPath('data.files.0.checksum', $file->checksum);
        $this->getJson('/api/v1/data-exchange/files')->assertOk()->assertJsonPath('meta.total', 1);
        $this->get('/api/v1/data-exchange/files/'.$file->id.'/download')->assertOk();
        Storage::disk($file->disk)->put($file->path, 'tampered');
        $this->getJson('/api/v1/data-exchange/files/'.$file->id.'/download')->assertConflict();
        $this->getJson('/api/v1/data-exchange/runs?per_page=101')->assertUnprocessable();
    }

    private function importPreview(DataExchangeProfile $profile): DataExchangeImportPreview
    {
        $upload = UploadedFile::fake()->createWithContent('clients.csv', "client_number,name\nAPI-001,Imported through API\n");
        $id = $this->postJson('/api/v1/data-exchange/imports/dry-run', ['profile_id' => $profile->id, 'file' => $upload])->assertCreated()->json('data.id');

        return DataExchangeImportPreview::findOrFail($id);
    }

    #[Test]
    public function imports_show_reviewed_rows_commit_once_and_replay_without_duplicate_clients(): void
    {
        $p = $this->profile(['direction' => 'import', 'fields' => [['field_key' => 'client_number', 'output_key' => 'client_number'], ['field_key' => 'name', 'output_key' => 'name']]]);
        $preview = $this->importPreview($p);
        $this->getJson('/api/v1/data-exchange/imports/'.$preview->id.'?rows_limit=1')->assertOk()->assertJsonPath('data.rows.0.values.name', 'Imported through API');
        $url = '/api/v1/data-exchange/imports/'.$preview->id.'/commit';
        $headers = ['Idempotency-Key' => 'commit-import-01', 'If-Match' => DataExchangeRevision::of($preview)];
        $this->withHeaders($headers)->postJson($url)->assertOk()->assertJsonPath('data.status', 'committed');
        $this->withHeaders($headers)->postJson($url)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, Client::where('client_number', 'API-001')->count());
        $this->getJson('/api/v1/data-exchange/imports')->assertOk()->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function imports_refuse_target_changes_and_invalid_row_shapes(): void
    {
        $p = $this->profile(['direction' => 'import', 'fields' => [['field_key' => 'name', 'output_key' => 'name']]]);
        $preview = $this->importPreview($p);
        Client::factory()->create();
        $this->withHeaders(['Idempotency-Key' => 'stale-import-01', 'If-Match' => DataExchangeRevision::of($preview)])->postJson('/api/v1/data-exchange/imports/'.$preview->id.'/commit')->assertConflict();
        $upload = UploadedFile::fake()->createWithContent('bad.json', '[{"name":"Good"}, "invalid"]');
        $this->postJson('/api/v1/data-exchange/imports/dry-run', ['profile_id' => $p->id, 'file' => $upload])->assertUnprocessable();
        $this->assertSame(0, Client::where('name', 'Imported through API')->count());
    }

    #[Test]
    public function schedules_and_targets_have_typed_readback_pause_and_strict_compatibility(): void
    {
        $p = $this->profile();
        $target = $this->target($p);
        $body = ['profile_id' => $p->id, 'delivery_target_id' => $target->id, 'direction' => 'export', 'frequency' => 'weekly', 'run_time' => '09:30', 'weekdays' => [1, 3], 'active' => true];
        $result = $this->withHeaders(['Idempotency-Key' => 'schedule-create-1'])->postJson('/api/v1/data-exchange/schedules', $body)->assertCreated();
        $id = $result->json('data.id');
        $this->getJson('/api/v1/data-exchange/schedules/'.$id)->assertOk()->assertJsonPath('data.weekdays', [1, 3]);
        $this->withHeaders(['Idempotency-Key' => 'schedule-pause-1', 'If-Match' => $result->json('data.revision')])->patchJson('/api/v1/data-exchange/schedules/'.$id, ['active' => false])->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.next_run_at', null);
        $this->withHeaders(['Idempotency-Key' => 'schedule-import-1'])->postJson('/api/v1/data-exchange/schedules', array_replace($body, ['direction' => 'import']))->assertUnprocessable();
        $this->withHeaders(['Idempotency-Key' => 'target-denied-01'])->postJson('/api/v1/data-exchange/delivery-targets', ['name' => 'Bad', 'type' => 'local', 'direction' => 'export', 'filesystem_disk' => 'local', 'remote_path' => '../public', 'active' => true])->assertUnprocessable();
        $this->getJson('/api/v1/data-exchange/schedules')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/data-exchange/delivery-targets')->assertOk()->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function duplicate_due_dispatch_cannot_duplicate_run_and_revoked_operator_blocks_execution(): void
    {
        Queue::fake();
        $p = $this->profile();
        $s = DataExchangeSchedule::create(['profile_id' => $p->id, 'direction' => 'export', 'active' => true, 'frequency' => 'daily', 'created_by' => $this->actor->id, 'next_run_at' => now()->subMinute()]);
        $planner = app(PlanDueDataExchangeSchedules::class);
        $planner->handle();
        $planner->handle();
        $this->assertSame(1, DataExchangeScheduleOccurrence::count());
        $occurrence = DataExchangeScheduleOccurrence::firstOrFail();
        $job = new RunDataExchangeScheduleJob($s->id, $occurrence->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        $this->assertSame(1, DataExchangeRun::count());
        $s->forceFill(['next_run_at' => now()->subMinutes(2)])->save();
        $planner->handle();
        $second = DataExchangeScheduleOccurrence::latest('id')->first();
        $this->role->revokePermissionTo('data_exchange.run');
        app()->call([new RunDataExchangeScheduleJob($s->id, $second->id), 'handle']);
        $this->assertSame('blocked', $second->fresh()->status);
        $this->assertSame(1, DataExchangeRun::count());
    }

    #[Test]
    public function delivery_is_default_off_then_verifies_exact_destination_and_replays_attempt(): void
    {
        $p = $this->profile();
        $file = $this->file($p);
        $target = $this->target($p);
        $url = '/api/v1/data-exchange/files/'.$file->id.'/deliveries';
        $hash = $this->postJson($url.'/preview', ['target_id' => $target->id])->assertOk()->json('data.preview_hash');
        $body = ['target_id' => $target->id, 'preview_hash' => $hash];
        $this->withHeaders(['Idempotency-Key' => 'delivery-command-1'])->postJson($url, $body)->assertStatus(503);
        $this->assertSame(0, DataExchangeDeliveryAttempt::count());
        Config::set('data_exchange.api_delivery_enabled', true);
        $result = $this->withHeaders(['Idempotency-Key' => 'delivery-command-1'])->postJson($url, $body)->assertCreated()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.verified', true);
        $this->withHeaders(['Idempotency-Key' => 'delivery-command-1'])->postJson($url, $body)->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $result->json('data.id'));
        $this->assertSame(1, DataExchangeDeliveryAttempt::count());
        $this->assertSame($file->checksum, hash('sha256', Storage::disk('exchange-out')->get('exports/'.$file->filename)));
        $this->getJson('/api/v1/data-exchange/delivery-attempts')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/data-exchange/operations/'.$result->json('operation_id'))->assertOk()->assertJsonPath('data.result.data.verified', true);
        $this->login(['data_exchange.read']);
        $this->getJson('/api/v1/data-exchange/operations/'.$result->json('operation_id'))->assertForbidden();
    }

    #[Test]
    public function unknown_delivery_requires_readback_and_never_blind_retry(): void
    {
        $p = $this->profile();
        $file = $this->file($p);
        $target = $this->target($p);
        $delivery = app(\App\Modules\DataExchange\Actions\VerifiedDataExchangeDelivery::class);
        $attempt = $delivery->prepare($file, $target);
        $attempt->forceFill(['status' => 'unknown'])->save();
        $this->postJson('/api/v1/data-exchange/delivery-attempts/'.$attempt->id.'/retry', ['target_id' => $target->id, 'preview_hash' => $delivery->preview($file, $target)['preview_hash']])->assertConflict();
        $this->postJson('/api/v1/data-exchange/delivery-attempts/'.$attempt->id.'/reconcile')->assertOk()->assertJsonPath('data.status', 'unknown');
        Storage::disk('exchange-out')->put('exports/'.$file->filename, Storage::disk($file->disk)->get($file->path));
        $this->postJson('/api/v1/data-exchange/delivery-attempts/'.$attempt->id.'/reconcile')->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.verified', true);
    }

    #[Test]
    public function stored_secret_fields_are_rejected_at_execution_even_if_profile_predates_current_rules(): void
    {
        $p = $this->profile();
        $p->fields()->create(['source_key' => 'clients', 'field_key' => 'password', 'output_key' => 'secret', 'active' => true]);
        $this->getJson('/api/v1/data-exchange/profiles/'.$p->id.'/runs/preview')->assertUnprocessable();
        $this->assertSame(0, DataExchangeRun::count());
    }

    #[Test]
    public function false_or_exception_during_remote_write_is_unknown_and_not_retryable(): void
    {
        foreach (['false', 'exception'] as $failure) {
            $p = $this->profile(['key' => 'write_'.$failure]);
            $file = $this->file($p);
            $target = $this->target($p);
            $delivery = app(\App\Modules\DataExchange\Actions\VerifiedDataExchangeDelivery::class);
            $attempt = $delivery->prepare($file, $target);
            $manager = Storage::getFacadeRoot();
            $source = Storage::disk('local');
            $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
            $expectation = $disk->shouldReceive('put')->once();
            if ($failure === 'false') {
                $expectation->andReturn(false);
            } else {
                $expectation->andThrow(new \RuntimeException('Transport interrupted.'));
            }
            Storage::shouldReceive('disk')->with('local')->andReturn($source);
            Storage::shouldReceive('disk')->with('exchange-out')->andReturn($disk);
            try {
                $result = $delivery->perform($attempt);
                $this->assertSame('unknown', $result->status);
                $this->assertFalse(data_get($result->metadata, 'safe_to_retry'));
                $this->assertFalse(data_get($result->metadata, 'verified'));
            } finally {
                Storage::swap($manager);
            }
        }
    }

    #[Test]
    public function changed_profile_invalidates_import_and_bad_target_parent_cannot_deliver(): void
    {
        $p = $this->profile(['direction' => 'import', 'fields' => [['field_key' => 'name', 'output_key' => 'name']]]);
        $preview = $this->importPreview($p);
        $p->fields()->first()->forceFill(['output_key' => 'different'])->save();
        $this->withHeaders(['Idempotency-Key' => 'mapping-changed-01', 'If-Match' => DataExchangeRevision::of($preview)])->postJson('/api/v1/data-exchange/imports/'.$preview->id.'/commit')->assertConflict();
        $one = $this->profile(['key' => 'export_one']);
        $two = $this->profile(['key' => 'export_two']);
        $file = $this->file($one);
        $target = $this->target($two);
        $this->postJson('/api/v1/data-exchange/files/'.$file->id.'/deliveries/preview', ['target_id' => $target->id])->assertUnprocessable();
    }

    #[Test]
    public function foreign_operation_ids_and_unsupported_numeric_route_ids_are_denied(): void
    {
        $result = $this->withHeaders(['Idempotency-Key' => 'own-operation-01'])->postJson('/api/v1/data-exchange/profiles', $this->body())->assertCreated();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->assignRole($this->role);
        Sanctum::actingAs($other, ['*']);
        $this->getJson('/api/v1/data-exchange/operations/'.$result->json('operation_id'))->assertNotFound();
        $this->getJson('/api/v1/data-exchange/profiles/1wrong')->assertNotFound();
    }

    #[Test]
    public function failed_export_is_durable_and_replay_does_not_generate_or_write_again(): void
    {
        $p = $this->profile();
        $url = '/api/v1/data-exchange/profiles/'.$p->id.'/runs';
        $hash = $this->getJson($url.'/preview')->assertOk()->json('data.preview_hash');
        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $input = ['preview_hash' => $hash];
        $this->withHeaders(['Idempotency-Key' => 'failed-export-01'])->postJson($url, $input)->assertCreated()->assertJsonPath('data.status', 'failed');
        $this->withHeaders(['Idempotency-Key' => 'failed-export-01'])->postJson($url, $input)->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('data.status', 'failed');
        $this->assertSame(1, DataExchangeRun::count());
        $this->assertSame(0, DataExchangeFile::count());
    }

    #[Test]
    public function every_official_operation_has_typed_openapi_parameters_responses_and_explicit_scopes(): void
    {
        $reflection = new \ReflectionClass(\App\Modules\DataExchange\Resources\Api\V1\DataExchangeApiOpenApi::class);
        $operations = [];
        foreach ($reflection->getAttributes() as $attribute) {
            $node = $attribute->newInstance();
            if ($node instanceof \OpenApi\Annotations\Operation) {
                $operations[strtolower($node->method).' '.$node->path] = $node;
            }
        }
        $count = 0;
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (! str_starts_with($route->getName() ?? '', 'api.v1.data-exchange.')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = strtolower($method).' /'.$route->uri();
                $this->assertArrayHasKey($key, $operations, $key);
                $this->assertNotEmpty($operations[$key]->responses);
                $this->assertArrayHasKey('dx_permission', $route->defaults);
                $this->assertArrayHasKey('dx_ability', $route->defaults);
                $this->assertContains(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':data_exchange.'.$route->defaults['dx_ability'], $route->gatherMiddleware());
                $count++;
            }
        }
        $this->assertSame(35, $count);
        $this->assertCount($count, $operations);
    }

    #[Test]
    public function imports_cannot_bypass_provider_authority_or_store_secret_columns(): void
    {
        $p = $this->profile(['direction' => 'import', 'fields' => [['field_key' => 'name', 'output_key' => 'name']]]);
        $authority = \Mockery::mock(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class);
        $authority->shouldReceive('connection')->andReturn(new \App\Models\System\Integrations\Integration);
        $this->app->instance(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class, $authority);
        $upload = UploadedFile::fake()->createWithContent('clients.csv', "name\nNew provider-owned customer\n");
        $response = $this->postJson('/api/v1/data-exchange/imports/dry-run', ['profile_id' => $p->id, 'file' => $upload])->assertCreated()->assertJsonPath('data.invalid_count', 1);
        $preview = DataExchangeImportPreview::findOrFail($response->json('data.id'));
        $this->withHeaders(['Idempotency-Key' => 'provider-import-01', 'If-Match' => DataExchangeRevision::of($preview)])->postJson('/api/v1/data-exchange/imports/'.$preview->id.'/commit')->assertUnprocessable();
        $this->assertSame(0, Client::count());
        $secret = UploadedFile::fake()->createWithContent('bad.csv', "name,password\nIgnored,synthetic-secret-test-value\n");
        $this->postJson('/api/v1/data-exchange/imports/dry-run', ['profile_id' => $p->id, 'file' => $secret])->assertUnprocessable();
        $this->assertSame(1, DataExchangeImportPreview::count());
        $this->assertStringNotContainsString('synthetic-secret-test-value', DataExchangeImportPreview::first()->toJson());
    }

    #[Test]
    public function browser_builder_reports_stale_save_without_overwriting_newer_configuration(): void
    {
        $p = $this->profile();
        $this->actingAs($this->actor);
        $editor = \Livewire\Livewire::test(\App\Modules\DataExchange\Livewire\Admin\ProfileBuilder::class, ['profile' => $p]);
        $p->forceFill(['name' => 'Newer server edit'])->save();
        $editor->call('save')->assertHasErrors(['revision'])->assertSee('The record changed; read it again.');
        $this->assertSame('Newer server edit', $p->fresh()->name);
    }

    #[Test]
    public function target_edits_file_metadata_audit_and_explicit_templates_have_exact_readback(): void
    {
        $p = $this->profile();
        $file = $this->file($p);
        $this->getJson('/api/v1/data-exchange/files/'.$file->id)->assertOk()->assertJsonPath('data.checksum', $file->checksum)->assertJsonMissingPath('data.path');
        $this->getJson('/api/v1/data-exchange/audit-events?profile_id='.$p->id)->assertOk()->assertJsonPath('meta.total', 3)->assertJsonMissingPath('data.0.metadata');
        $target = $this->target($p);
        $url = '/api/v1/data-exchange/delivery-targets/'.$target->id;
        $revision = $this->getJson($url)->assertOk()->json('data.revision');
        $headers = ['Idempotency-Key' => 'target-pause-001', 'If-Match' => $revision];
        $this->withHeaders($headers)->patchJson($url, ['active' => false, 'notes' => null])->assertOk()->assertJsonPath('data.active', false);
        $this->withHeaders($headers)->patchJson($url, ['active' => false, 'notes' => null])->assertOk()->assertJsonPath('replayed', true);
        $this->assertFalse($target->fresh()->active);
        $this->withHeaders(['Idempotency-Key' => 'target-resume-01', 'If-Match' => DataExchangeRevision::of($target->fresh())])->patchJson($url, ['active' => true, 'notes' => 'Approved reference only'])->assertOk()->assertJsonPath('data.active', true)->assertJsonPath('data.notes', 'Approved reference only');
        $this->withHeaders(['Idempotency-Key' => 'template-init-01'])->postJson('/api/v1/data-exchange/profiles/templates', ['source_key' => 'clients'])->assertCreated()->assertJsonPath('data.key', 'clients_basic_import')->assertJsonPath('data.direction', 'import');
        $this->getJson('/api/v1/data-exchange/profiles?q=Basic')->assertOk()->assertJsonPath('meta.total', 1);
    }
}
