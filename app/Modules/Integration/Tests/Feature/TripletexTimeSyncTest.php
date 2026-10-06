<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Models\System\Integrations\Integration;
use App\Modules\DataExchange\Models\TripletexWorkdaySyncState as State;
use App\Modules\DataExchange\Services\SyncTripletexWorkdays;
use App\Modules\Workday\Actions\MutateWorkday;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Models\WorkdayRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TripletexTimeSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private Integration $connection;

    private array $remote = [];

    private int $writes = 0;

    private bool $loseCreateResponse = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(20, 0));
        config(['workday.enabled' => true, 'tripletex.enabled' => true, 'tripletex.writes_enabled' => true]);
        CommonSetting::where('type', 'workday')->where('name', 'manual_workflow')->update([
            'json' => json_encode(['enabled' => true, 'retention_years' => 3, 'version' => 0]),
        ]);
        $this->worker = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->worker->assignRole(Role::findOrCreate('Tech', 'web'));
        $this->worker->givePermissionTo(['workday.view_own', 'workday.manage_own', 'workday.view_all', 'integration.tripletex_manage']);
        $this->connection = Integration::create(['name' => 'Synthetic', 'type' => 'tripletex', 'status' => 'active',
            'config' => ['company_id' => 42, 'environment' => 'test', 'version' => 1, 'read_verified_at' => now()->toIso8601String(),
                'write_contract_verified' => true, 'time_catalog' => ['employees' => [['id' => 2, 'firstName' => 'Test', 'lastName' => 'Worker']],
                    'activities' => [['id' => 3, 'name' => 'Work']], 'projects' => []],
                'time_mappings' => [(string) $this->worker->id => ['employee_id' => 2, 'activity_id' => 3,
                    'start_date' => '2026-10-05', 'timezone' => 'Europe/Oslo']]]]);
        $this->connection->setSecret('refresh_token', 'synthetic-token-no-live-requests');
        $this->connection->save();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();
            if (str_contains($path, 'createFromRefreshToken')) {
                return Http::response(['value' => ['token' => 'test-session']]);
            }
            if (str_contains($path, 'whoAmI')) {
                return Http::response(['value' => ['company' => ['id' => 42]]]);
            }
            if (preg_match('~/timesheet/entry/(\d+)$~', $path, $match)) {
                $id = (int) $match[1];
                if (! isset($this->remote[$id])) {
                    return Http::response([], 404);
                }
                if ($method === 'GET') {
                    return Http::response(['value' => $this->remote[$id]]);
                }
                $this->writes++;
                if ($method === 'DELETE') {
                    unset($this->remote[$id]);

                    return Http::response([], 204);
                }
                $this->remote[$id] = $request->data() + ['locked' => false];
                $this->remote[$id]['version']++;

                return Http::response(['value' => $this->remote[$id]]);
            }
            if (str_ends_with($path, '/timesheet/entry')) {
                if ($method === 'GET') {
                    return Http::response(['values' => array_values($this->remote), 'fullResultSize' => count($this->remote)]);
                }
                $this->writes++;
                $this->remote[100] = $request->data() + ['id' => 100, 'version' => 0, 'locked' => false];
                if ($this->loseCreateResponse) {
                    $this->loseCreateResponse = false;
                    throw new \RuntimeException('Synthetic transport interruption');
                }

                return Http::response(['value' => $this->remote[100]], 201);
            }
            throw new \RuntimeException('Unexpected fake HTTP path');
        });
    }

    /** The scheduled entry point must honor a paused connection without contacting the provider. */
    public function test_scheduled_command_skips_paused_connections(): void
    {
        $this->connection->update(['status' => 'disabled']);

        $this->artisan('tripletex:sync-time')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertDatabaseCount('tripletex_workday_sync_states', 0);
        $this->assertDatabaseCount('workdays', 0);
    }

    private function save(float $hours, int $version = 0, string $comment = 'Work'): array
    {
        return app(MutateWorkday::class)->handle($this->worker, 'save', '2026-10-05', [
            'version' => $version, 'timezone' => 'Europe/Oslo', 'description' => 'Work',
            'durations' => [['activity_id' => 3, 'project_id' => null, 'hours' => $hours, 'comment' => $comment]],
        ], (string) Str::uuid(), 'ui');
    }

    private function runSync(): void
    {
        app(SyncTripletexWorkdays::class)->day($this->connection->id, $this->worker->id, '2026-10-05');
    }

    private function provider(float $hours = 0.75): array
    {
        return ['id' => 100, 'version' => 0, 'locked' => false, 'date' => '2026-10-05', 'employee' => ['id' => 2],
            'activity' => ['id' => 3], 'project' => null, 'hours' => $hours, 'comment' => 'Work'];
    }

    public function test_save_is_effective_without_confirmation_and_crud_syncs_both_directions(): void
    {
        $saved = $this->save(0.75);
        $this->assertSame('recorded', $saved['data']['current']['state']);
        $this->assertSame($saved['data']['current']['id'], $saved['data']['confirmed']['id']);
        $this->runSync();
        $this->assertEquals(0.75, $this->remote[100]['hours']);
        $this->assertSame('synced', State::sole()->status);
        $this->save(1.25, 1);
        $this->runSync();
        $this->assertEquals(1.25, $this->remote[100]['hours']);
        $this->remote[100]['hours'] = 1.5;
        $this->remote[100]['version']++;
        $this->runSync();
        $day = Workday::sole();
        $this->assertEquals(90, $day->currentRevision->snapshot['actual_minutes']);
        $this->assertSame('sync', $day->currentRevision->origin);
        $this->assertTrue(User::find($day->currentRevision->author_id)->isSystemActor());
        unset($this->remote[100]);
        $this->runSync();
        $this->assertEquals(0, Workday::sole()->currentRevision->snapshot['actual_minutes']);
        $this->save(0.5, Workday::sole()->version);
        $this->runSync();
        $this->assertEquals(0.5, $this->remote[100]['hours']);
        $this->save(0, Workday::sole()->version);
        $this->runSync();
        $this->assertSame([], $this->remote);
        $before = $this->writes;
        $this->runSync();
        $this->assertSame($before, $this->writes);
    }

    public function test_imported_time_can_be_changed_locally_without_accepting_or_confirming(): void
    {
        $this->remote[100] = $this->provider(0.01);
        $this->runSync();
        $this->assertEquals(0.6, Workday::sole()->currentRevision->snapshot['actual_minutes']);
        $this->save(0.02, 1);
        $this->runSync();
        $this->assertEquals(0.02, $this->remote[100]['hours']);
        $this->assertSame('synced', State::sole()->status);
    }

    public function test_switch_pauses_both_directions_and_preserves_pending_time(): void
    {
        $this->worker->assignRole(Role::findOrCreate('Admin', 'web'));
        $this->save(0.75);
        $this->actingAs($this->worker)->post(route('tech.admin.system.integrations.tripletex.time-sync', $this->connection), ['version' => 1, 'enabled' => 0])->assertRedirect();
        $this->runSync();
        Http::assertNothingSent();
        $this->assertSame('pending', State::sole()->status);
        $this->actingAs($this->worker)->post(route('tech.admin.system.integrations.tripletex.time-sync', $this->connection), ['version' => 1, 'enabled' => 1])->assertStatus(409);
        $this->post(route('tech.admin.system.integrations.tripletex.time-sync', $this->connection), ['version' => 2, 'enabled' => 1])->assertRedirect();
        $this->runSync();
        $this->assertCount(1, $this->remote);
        $this->connection->refresh()->update(['status' => 'disabled']);
        $this->remote[100]['hours'] = 2;
        $this->runSync();
        $this->assertEquals(45, Workday::sole()->currentRevision->snapshot['actual_minutes']);
    }

    public function test_conflicting_edits_and_locked_rows_are_not_overwritten(): void
    {
        $this->save(0.75);
        $this->runSync();
        $this->save(1, 1);
        $this->remote[100]['hours'] = 2;
        $this->remote[100]['version']++;
        $this->runSync();
        $this->assertSame('both_sides_changed', State::sole()->error_code);
        $this->assertEquals(2, $this->remote[100]['hours']);
        $this->assertEquals(60, Workday::sole()->currentRevision->snapshot['actual_minutes']);
        $this->remote[100]['hours'] = 0.75;
        $this->remote[100]['version'] = 0;
        $this->remote[100]['locked'] = true;
        $this->runSync();
        $this->assertSame(1, $this->writes);
    }

    public function test_ambiguous_create_recovers_by_business_key_without_duplicate(): void
    {
        $this->save(0.75);
        $this->loseCreateResponse = true;
        $this->runSync();
        $this->assertNotNull(State::sole()->intent);
        $this->assertSame(1, $this->writes);
        $this->runSync();
        $this->assertNull(State::sole()->intent);
        $this->assertSame('synced', State::sole()->status);
        $this->assertSame(1, $this->writes);
    }

    public function test_private_drafts_and_unmapped_employees_are_never_imported_or_exported(): void
    {
        app(MutateWorkday::class)->handle($this->worker, 'draft', '2026-10-05', [
            'version' => 0, 'timezone' => 'Europe/Oslo', 'description' => 'Private', 'breaks' => [],
            'intervals' => [['start' => '2026-10-05T08:00', 'end' => '2026-10-05T09:00']],
        ], (string) Str::uuid(), 'ui');
        $this->remote[100] = $this->provider();
        $this->runSync();
        $this->assertSame('draft', Workday::sole()->currentRevision->state);
        $this->assertSame('private_draft_or_expired', State::sole()->error_code);
        Http::assertNothingSent();
        app(SyncTripletexWorkdays::class)->day($this->connection->id, $this->worker->id + 900, '2026-10-05');
        $this->assertCount(1, State::all());
    }

    public function test_minute_input_rounds_once_and_retains_original_clocks_as_history(): void
    {
        $saved = app(MutateWorkday::class)->handle($this->worker, 'save', '2026-10-05', [
            'version' => 0, 'timezone' => 'Europe/Oslo', 'description' => 'One minute', 'breaks' => [],
            'intervals' => [['start' => '2026-10-05T08:00', 'end' => '2026-10-05T08:01']],
        ], (string) Str::uuid(), 'ui');
        $snapshot = $saved['data']['current']['snapshot'];
        $this->assertEquals(1.2, $snapshot['actual_minutes']);
        $this->assertSame([], $snapshot['intervals']);
        $this->assertCount(1, $snapshot['original_intervals']);
        $this->runSync();
        $this->runSync();
        $this->assertEquals(0.02, $this->remote[100]['hours']);
        $this->assertSame(1, Workday::sole()->version);
        $this->assertSame(1, $this->writes);
    }

    public function test_browser_switch_and_duration_editor_are_rendered_and_save_effective_time(): void
    {
        $this->worker->assignRole(Role::findOrCreate('Admin', 'web'));
        $this->actingAs($this->worker)->get(route('tech.admin.system.integrations.tripletex.index'))->assertOk()
            ->assertSee('role="switch"', false)->assertSee('Synchronize time registrations automatically')
            ->assertSee('Leave blank to keep the existing token')->assertDontSee('synthetic-token-no-live-requests');
        $saved = $this->save(0.75);
        $this->get('/tech/workdays/'.$saved['data']['id'])->assertOk()->assertSee('Time by duration')->assertSee('Save time')->assertDontSee('Start correction');
        $this->put('/tech/workdays/2026-10-05/save', ['version' => 1, 'timezone' => 'Europe/Oslo',
            'description' => 'Work', 'durations' => [['activity_id' => 3, 'hours' => '0.50']],
            'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->assertEquals(30, Workday::sole()->currentRevision->snapshot['actual_minutes']);
        $overview = app(\App\Modules\Workday\Queries\ConfirmedWorkdays::class)->listing($this->worker, ['from' => '2026-10-05', 'to' => '2026-10-05']);
        $this->assertEquals(30, $overview['totals']['actual_minutes']);
    }

    public function test_switch_needs_explicit_permission_and_mapping(): void
    {
        $this->worker->assignRole(Role::findOrCreate('Admin', 'web'));
        $this->worker->revokePermissionTo('integration.tripletex_manage');
        $this->actingAs($this->worker)->post(route('tech.admin.system.integrations.tripletex.time-sync', $this->connection),
            ['version' => 1, 'enabled' => 0])->assertForbidden();
        $this->assertSame('active', $this->connection->fresh()->status);
        $this->worker->givePermissionTo('integration.tripletex_manage');
        $config = $this->connection->config;
        $config['time_mappings'] = [];
        $this->connection->update(['status' => 'disabled', 'config' => $config]);
        $this->postJson(route('tech.admin.system.integrations.tripletex.time-sync', $this->connection),
            ['version' => 1, 'enabled' => 1])->assertStatus(422);
        $this->assertSame('disabled', $this->connection->fresh()->status);
    }

    public function test_empty_date_can_later_import_and_retention_never_deletes_provider_time(): void
    {
        $this->runSync();
        $this->assertSame(0, Workday::count());
        $this->remote[100] = $this->provider();
        $this->runSync();
        $this->assertEquals(45, Workday::sole()->currentRevision->snapshot['actual_minutes']);
        Workday::sole()->update(['expires_at' => now()->subMinute()]);
        $this->runSync();
        $this->assertSame(0, $this->writes);
        $this->assertCount(1, $this->remote);
    }

    public function test_api_save_requires_write_scope_and_is_versioned_and_idempotent(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->worker, ['workdays.write']);
        $body = ['version' => 0, 'timezone' => 'Europe/Oslo', 'description' => 'Work',
            'durations' => [['activity_id' => 3, 'hours' => 0.75]]];
        $headers = ['Idempotency-Key' => 'time-save-contract'];
        $this->putJson('/api/v1/workdays/2026-10-05/save', $body, $headers)->assertOk()->assertJsonPath('data.current.state', 'recorded');
        $this->putJson('/api/v1/workdays/2026-10-05/save', $body, $headers)->assertOk();
        $this->assertSame(1, WorkdayRevision::count());
        $this->putJson('/api/v1/workdays/2026-10-05/save', $body, ['Idempotency-Key' => 'different-save-key'])->assertStatus(409);
        \Laravel\Sanctum\Sanctum::actingAs($this->worker, ['workdays.read']);
        $this->putJson('/api/v1/workdays/2026-10-05/save', $body, $headers)->assertForbidden();
    }
}
