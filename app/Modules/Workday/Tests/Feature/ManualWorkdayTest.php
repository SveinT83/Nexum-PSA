<?php

namespace App\Modules\Workday\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Models\WorkdayRevision;
use App\Modules\Workday\Support\WorkdaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManualWorkdayTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private array $abilities = ['workdays.read', 'workdays.write', 'workdays.confirm', 'workdays.settings'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['workday.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-28 18:00', 'Europe/Oslo'));
        CommonSetting::where('type', 'workday')->where('name', 'manual_workflow')
            ->update(['json' => json_encode(['enabled' => true, 'retention_years' => 3, 'version' => 0])]);
        $role = Role::findOrCreate('Tech', 'web');
        $role->givePermissionTo(['workday.view_own', 'workday.manage_own', 'workday.confirm_own']);
        $this->worker = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->worker->assignRole($role);
        Sanctum::actingAs($this->worker, $this->abilities);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(string $date = '2026-10-01'): array
    {
        return ['version' => 0, 'timezone' => 'Europe/Oslo', 'description' => 'Work - unspecified',
            'intervals' => [['start' => $date.'T08:00', 'end' => $date.'T16:00', 'description' => 'Support']],
            'breaks' => [['start' => $date.'T12:00', 'end' => $date.'T12:30', 'included' => false]]];
    }

    private function save(?array $data = null, string $date = '2026-10-01', ?string $key = null)
    {
        return $this->putJson('/api/v1/workdays/'.$date.'/draft', $data ?? $this->payload($date),
            ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function postOperation(string $id, string $operation, array $data, ?string $key = null)
    {
        return $this->postJson('/api/v1/workdays/'.$id.'/'.$operation, $data, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function confirmDay(array $day): array
    {
        $preview = $this->postOperation($day['id'], 'preview', ['version' => $day['version']])->assertOk()->json('preview');

        return $this->postOperation($day['id'], 'confirm', ['version' => $day['version'],
            'preview_token' => $preview['token'], 'confirmed' => true])->assertOk()->json('data');
    }

    public function test_draft_preview_confirm_readback_and_history_have_correct_actual_minutes(): void
    {
        $day = $this->save()->assertOk()->assertJsonPath('data.current.snapshot.actual_minutes', 450)->json('data');
        $this->assertNull($day['confirmed']);
        $confirmed = $this->confirmDay($day);
        $this->assertSame(2, $confirmed['version']);
        $this->assertSame(450, $confirmed['confirmed']['snapshot']['actual_minutes']);
        $this->getJson('/api/v1/workdays/'.$day['id'])->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson('/api/v1/workdays/'.$day['id'].'/history')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/workdays')->assertOk()->assertJsonPath('total', 1);
        $this->assertSame(1, Workday::count());
        $this->assertSame(2, WorkdayRevision::count());
    }

    public function test_included_breaks_count_and_treatment_requires_explicit_choice(): void
    {
        $input = $this->payload();
        unset($input['breaks'][0]['included']);
        $this->save($input)->assertUnprocessable()->assertJsonValidationErrors('breaks.0.included');
        $input['breaks'][0]['included'] = true;
        $this->save($input)->assertOk()->assertJsonPath('data.current.snapshot.actual_minutes', 480)
            ->assertJsonPath('data.current.snapshot.included_break_minutes', 30);
    }

    public function test_overnight_actual_time_and_adjacent_date_overlap(): void
    {
        $input = $this->payload();
        $input['intervals'] = [['start' => '2026-10-01T22:00', 'end' => '2026-10-02T06:00']];
        $input['breaks'] = [];
        $this->save($input)->assertOk()->assertJsonPath('data.current.snapshot.actual_minutes', 480);
        $next = $this->payload('2026-10-02');
        $next['intervals'] = [['start' => '2026-10-02T05:00', 'end' => '2026-10-02T08:00']];
        $next['breaks'] = [];
        $this->save($next, '2026-10-02')->assertUnprocessable()->assertJsonValidationErrors('intervals');
        $next['intervals'][0]['start'] = '2026-10-02T06:00';
        $this->save($next, '2026-10-02')->assertOk();
    }

    public function test_dst_uses_elapsed_time_and_rejects_gaps_folds_and_wrong_offsets(): void
    {
        $input = $this->payload('2026-10-25');
        $input['intervals'] = [['start' => '2026-10-25T01:00', 'end' => '2026-10-25T04:00']];
        $input['breaks'] = [];
        $this->save($input, '2026-10-25')->assertOk()->assertJsonPath('data.current.snapshot.actual_minutes', 240);
        $input['version'] = 1;
        $input['intervals'] = [['start' => '2026-10-25T02:30', 'end' => '2026-10-25T04:00']];
        $this->save($input, '2026-10-25')->assertUnprocessable();
        $input['intervals'][0]['start'] = '2026-10-25T02:30+02:00';
        $this->save($input, '2026-10-25')->assertOk()->assertJsonPath('data.current.snapshot.actual_minutes', 150);
        $input = $this->payload('2026-03-29');
        $input['breaks'] = [];
        $input['intervals'] = [['start' => '2026-03-29T02:30+01:00', 'end' => '2026-03-29T04:00']];
        $this->save($input, '2026-03-29')->assertUnprocessable();
        $input['intervals'][0]['start'] = '2026-03-29T02:30';
        $this->save($input, '2026-03-29')->assertUnprocessable();
        $input['intervals'][0]['start'] = '2026-03-29T01:00';
        $this->save($input, '2026-03-29')->assertOk()->assertJsonPath('data.current.snapshot.actual_minutes', 120);
    }

    public function test_invalid_intervals_breaks_and_claimed_work_dates_are_rejected(): void
    {
        $input = $this->payload();
        $input['intervals'][] = $input['intervals'][0];
        $this->save($input)->assertUnprocessable();
        $input = $this->payload();
        $input['breaks'][0]['start'] = '2026-10-01T07:00';
        $this->save($input)->assertUnprocessable();
        $input = $this->payload();
        $input['breaks'][] = $input['breaks'][0];
        $this->save($input)->assertUnprocessable();
        $this->save($this->payload('2026-10-02'))->assertUnprocessable();
        $input = $this->payload();
        $input['intervals'][0]['end'] = '2026-10-01T08:00';
        $this->save($input)->assertUnprocessable();
        $input['intervals'][0]['end'] = '2026-10-03T08:00';
        $this->save($input)->assertUnprocessable();
        $this->assertSame(0, Workday::count());
    }

    public function test_retry_returns_original_receipt_and_key_reuse_conflicts(): void
    {
        $key = (string) Str::uuid();
        $first = $this->save(null, '2026-10-01', $key)->assertOk()->json();
        $this->assertSame($first, $this->save(null, '2026-10-01', $key)->assertOk()->json());
        $input = $this->payload();
        $input['description'] = 'Different';
        $this->save($input, '2026-10-01', $key)->assertConflict();
        $this->assertSame(1, WorkdayRevision::count());
        $this->assertSame(1, DB::table('workday_mutation_receipts')->count());
    }

    public function test_version_prevents_ui_api_lost_updates_and_stale_preview_confirmation(): void
    {
        $day = $this->save()->assertOk()->json('data');
        $preview = $this->postOperation($day['id'], 'preview', ['version' => 1])->assertOk()->json('preview');
        $this->save()->assertConflict();
        $input = $this->payload();
        $input['version'] = 1;
        $input['description'] = 'Revised actual work';
        $this->actingAs($this->worker, 'web')->put('/tech/workdays/2026-10-01/draft', $input + ['request_key' => (string) Str::uuid()])
            ->assertRedirect();
        Sanctum::actingAs($this->worker, $this->abilities);
        $this->postOperation($day['id'], 'confirm', ['version' => 1, 'preview_token' => $preview['token'], 'confirmed' => true])->assertConflict();
        $this->postOperation($day['id'], 'confirm', ['version' => 2, 'preview_token' => $preview['token'], 'confirmed' => true])->assertConflict();
        $this->assertNull(Workday::first()->confirmed_revision_id);
    }

    public function test_correction_preserves_confirmed_snapshot_and_original_expiry_until_reconfirmed(): void
    {
        $confirmed = $this->confirmDay($this->save()->assertOk()->json('data'));
        $expiry = $confirmed['expires_at'];
        $this->save(array_replace($this->payload(), ['version' => 2]))->assertConflict();
        $correction = $this->postOperation($confirmed['id'], 'corrections', ['version' => 2, 'reason' => 'Break counted as work'])->assertOk()->json('data');
        $this->assertSame(450, $correction['confirmed']['snapshot']['actual_minutes']);
        $input = $this->payload();
        $input['version'] = 3;
        $input['breaks'][0]['included'] = true;
        $draft = $this->save($input)->assertOk()->assertJsonPath('data.confirmed.snapshot.actual_minutes', 450)->json('data');
        $reconfirmed = $this->confirmDay($draft);
        $this->assertSame(480, $reconfirmed['confirmed']['snapshot']['actual_minutes']);
        $this->assertSame($expiry, $reconfirmed['expires_at']);
        $this->assertSame('Break counted as work', $reconfirmed['confirmed']['correction_reason']);
        $this->assertSame(5, WorkdayRevision::count());
        $this->assertSame(450, WorkdayRevision::where('version', 2)->first()->snapshot['actual_minutes']);
    }

    public function test_confirmation_requires_explicit_acceptance_unexpired_preview_and_completed_work(): void
    {
        $day = $this->save()->assertOk()->json('data');
        $preview = $this->postOperation($day['id'], 'preview', ['version' => 1])->assertOk()->json('preview');
        $this->postOperation($day['id'], 'confirm', ['version' => 1, 'preview_token' => $preview['token'], 'confirmed' => false])->assertUnprocessable();
        Carbon::setTestNow(now()->addMinutes(31));
        $this->postOperation($day['id'], 'confirm', ['version' => 1, 'preview_token' => $preview['token'], 'confirmed' => true])->assertConflict();
        $future = $this->save($this->payload('2026-10-29'), '2026-10-29')->assertOk()->json('data');
        $preview = $this->postOperation($future['id'], 'preview', ['version' => 1])->assertOk()->json('preview');
        $this->postOperation($future['id'], 'confirm', ['version' => 1, 'preview_token' => $preview['token'], 'confirmed' => true])->assertUnprocessable();
    }

    public function test_confirm_retry_does_not_append_or_confirm_again(): void
    {
        $day = $this->save()->assertOk()->json('data');
        $preview = $this->postOperation($day['id'], 'preview', ['version' => 1])->assertOk()->json('preview');
        $key = (string) Str::uuid();
        $body = ['version' => 1, 'preview_token' => $preview['token'], 'confirmed' => true];
        $first = $this->postOperation($day['id'], 'confirm', $body, $key)->assertOk()->json();
        $this->assertSame($first, $this->postOperation($day['id'], 'confirm', $body, $key)->assertOk()->json());
        $this->assertSame(2, WorkdayRevision::count());
    }

    public function test_ownership_is_enforced_even_for_superuser_and_wildcard_tokens(): void
    {
        $day = $this->save()->assertOk()->json('data');
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $role = Role::findOrCreate('Superuser', 'web');
        $role->givePermissionTo(Permission::where('name', 'like', 'workday.%')->get());
        $other->assignRole($role);
        Sanctum::actingAs($other, ['*']);
        $this->getJson('/api/v1/workdays')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/workdays/'.$day['id'])->assertNotFound();
        $this->getJson('/api/v1/workdays/'.$day['id'].'/history')->assertNotFound();
        foreach (['preview', 'confirm', 'corrections'] as $op) {
            $this->postOperation($day['id'], $op, ['version' => 1])->assertNotFound();
        }
        $input = $this->payload();
        $input['user_id'] = $this->worker->id;
        $this->save($input)->assertUnprocessable();
        $this->assertSame(1, Workday::count());
    }

    public function test_permissions_and_token_scopes_are_both_required(): void
    {
        Sanctum::actingAs($this->worker, ['workdays.read']);
        $this->save()->assertForbidden();
        $this->getJson('/api/v1/workday-settings')->assertForbidden();
        Sanctum::actingAs($this->worker, $this->abilities);
        Role::findByName('Tech', 'web')->revokePermissionTo('workday.manage_own');
        $this->worker->unsetRelation('roles')->unsetRelation('permissions');
        $this->save()->assertForbidden();
        $this->getJson('/api/v1/workdays')->assertOk();
    }

    public function test_inactive_system_and_portal_only_users_are_denied(): void
    {
        foreach ([['status' => User::STATUS_DISABLED], ['is_system_actor' => true]] as $state) {
            $user = User::factory()->create(['status' => User::STATUS_ACTIVE] + $state);
            // Explicit assignment below avoids array-union overriding the disabled fixture.
            $user->forceFill($state)->save();
            $user->assignRole('Tech');
            Sanctum::actingAs($user, ['*']);
            $this->getJson('/api/v1/workdays')->assertForbidden();
        }
        $portal = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($portal, ['*']);
        $this->getJson('/api/v1/workdays')->assertForbidden();
    }

    public function test_default_off_and_settings_versioned_audited_updates(): void
    {
        config(['workday.enabled' => false]);
        $this->getJson('/api/v1/workdays')->assertNotFound();
        $this->getJson('/api/v1/workday-settings')->assertForbidden();
        $this->worker->givePermissionTo('workday.manage_settings');
        $this->getJson('/api/v1/workday-settings')->assertOk()->assertJsonPath('data.effective_enabled', false)
            ->assertJsonPath('data.retention_years', 3);
        $key = (string) Str::uuid();
        $body = ['version' => 0, 'enabled' => false];
        $first = $this->patchJson('/api/v1/workday-settings', $body, ['Idempotency-Key' => $key])->assertOk()->json();
        $this->assertSame($first, $this->patchJson('/api/v1/workday-settings', $body, ['Idempotency-Key' => $key])->assertOk()->json());
        $this->patchJson('/api/v1/workday-settings', ['version' => 0, 'enabled' => true], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        config(['workday.enabled' => true]);
        $this->assertFalse(app(WorkdaySettings::class)->enabled());
        $this->getJson('/api/v1/workdays')->assertNotFound();
    }

    public function test_browser_can_save_preview_confirm_correct_and_read_history(): void
    {
        $this->actingAs($this->worker, 'web');
        $this->get('/tech/workdays/create')->assertOk()->assertSee('Actual time')->assertSee('Add break');
        $input = $this->payload();
        unset($input['breaks']);
        $this->put('/tech/workdays/2026-10-01/draft', $input + ['request_key' => (string) Str::uuid()])->assertRedirect();
        $day = Workday::first();
        $this->get('/tech/workdays/'.$day->uuid)->assertOk()->assertSee('Saved draft, version 1')->assertSee('Revision history');
        $page = $this->post('/tech/workdays/'.$day->uuid.'/preview', ['version' => 1, 'request_key' => (string) Str::uuid()])->assertOk()
            ->assertSee('Confirm my workday')->assertSee('480');
        $token = $page->viewData('preview')['token'];
        $this->post('/tech/workdays/'.$day->uuid.'/confirm', ['version' => 1, 'preview_token' => $token,
            'confirmed' => '1', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->get('/tech/workdays/'.$day->uuid)->assertOk()->assertSee('Confirmed version 2');
        $this->post('/tech/workdays/'.$day->uuid.'/corrections', ['version' => 2, 'reason' => 'Forgot break',
            'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->get('/tech/workdays/'.$day->uuid)->assertOk()->assertSee('A correction is in progress')->assertSee('Forgot break');
        $this->get('/tech/workdays')->assertOk()->assertSee('Correction draft');
    }

    public function test_workday_is_isolated_from_task_ticket_and_billing_writes(): void
    {
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = strtolower($query->sql);
            }
        });
        $this->confirmDay($this->save()->assertOk()->json('data'));
        $this->assertNotEmpty($writes);
        foreach ($writes as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\b(task_time_entries|ticket_time_entries|commercial_[a-z_]+|timebank[a-z_]*)\b/', $sql);
        }
    }

    public function test_retention_deadline_cannot_be_extended_by_correction(): void
    {
        $this->save($this->payload('2023-10-01'), '2023-10-01')->assertUnprocessable();
        $day = $this->save()->assertOk()->json('data');
        $this->assertSame('2029-10-01T22:00:00+00:00', $day['expires_at']);
        Carbon::setTestNow('2029-10-02 00:01:00');
        $this->getJson('/api/v1/workdays/'.$day['id'])->assertNotFound();
        $this->getJson('/api/v1/workdays')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_history_pagination_and_bounded_page_size(): void
    {
        $day = $this->save()->assertOk()->json('data');
        $this->confirmDay($day);
        $this->getJson('/api/v1/workdays/'.$day['id'].'/history?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 2);
        $this->getJson('/api/v1/workdays?per_page=101')->assertUnprocessable();
    }

    public function test_personal_persisted_token_works_but_coordinator_bound_token_cannot_use_workday(): void
    {
        $token = $this->worker->createToken('Synthetic employee test', $this->abilities);
        $this->worker->withAccessToken($token->accessToken);
        $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/workdays')->assertOk();
        $workload = \App\Modules\Integration\Models\AiWorkloadProfile::create([
            'name' => 'Synthetic coordinator', 'slug' => 'synthetic-coordinator', 'purpose' => 'Test scope isolation',
            'processing_mode' => 'local_only', 'maximum_data_profile' => 'pseudonymized',
            'abilities' => ['worklog.read'], 'is_approved' => true, 'is_active' => true,
            'expires_at' => now()->addMonth(), 'approved_by' => $this->worker->id, 'approved_at' => now(),
            'created_by' => $this->worker->id,
        ]);
        \App\Modules\Integration\Models\AiWorkloadTokenBinding::create([
            'personal_access_token_id' => $token->accessToken->id, 'ai_workload_profile_id' => $workload->id,
            'expires_at' => now()->addWeek(), 'allowed_networks' => [], 'requests_per_minute' => 30,
            'created_by' => $this->worker->id,
        ]);
        $this->getJson('/api/v1/workdays')->assertForbidden();
        $this->save()->assertForbidden();
    }

    public function test_last_confirmed_intervals_still_reserve_time_while_correction_is_pending(): void
    {
        $input = $this->payload();
        $input['intervals'] = [['start' => '2026-10-01T22:00', 'end' => '2026-10-02T06:00']];
        $input['breaks'] = [];
        $day = $this->confirmDay($this->save($input)->assertOk()->json('data'));
        $this->postOperation($day['id'], 'corrections', ['version' => 2, 'reason' => 'Earlier finish'])->assertOk();
        $input['version'] = 3;
        $input['intervals'][0]['end'] = '2026-10-02T04:00';
        $this->save($input)->assertOk();
        $next = $this->payload('2026-10-02');
        $next['intervals'] = [['start' => '2026-10-02T05:00', 'end' => '2026-10-02T08:00']];
        $next['breaks'] = [];
        $this->save($next, '2026-10-02')->assertUnprocessable();
    }

    public function test_browser_settings_and_workday_navigation_are_permission_gated(): void
    {
        $this->worker->givePermissionTo('workday.manage_settings');
        config(['workday.enabled' => false]);
        $this->actingAs($this->worker, 'web')->get('/tech/admin/settings/workday')
            ->assertOk()->assertSee('The deployment switch is off')->assertSee('three years');
        $this->patch('/tech/admin/settings/workday', ['version' => 0, 'enabled' => '0', 'request_key' => (string) Str::uuid()])
            ->assertRedirect();
        $this->get('/tech/workdays')->assertNotFound();
        $this->assertFalse(app(WorkdaySettings::class)->read()['enabled']);
    }

    public function test_utc_input_round_trips_and_timezone_cannot_change_later(): void
    {
        $input = $this->payload();
        $input['intervals'] = [['start' => '2026-10-01T06:00:00Z', 'end' => '2026-10-01T14:00:00Z']];
        $this->save($input)->assertOk()->assertJsonPath('data.current.snapshot.actual_minutes', 450)
            ->assertJsonPath('data.current.snapshot.intervals.0.start', '2026-10-01T06:00:00Z');
        $input['version'] = 1;
        $input['timezone'] = 'UTC';
        $this->save($input)->assertConflict();
        $input = $this->payload();
        $input['intervals'][0]['start'] = '2026-02-30T08:00Z';
        $this->save($input)->assertUnprocessable();
    }

    public function test_migration_and_seeders_preserve_explicit_permission_revocations(): void
    {
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        Role::findOrCreate('Superuser', 'web');
        Role::findOrCreate('Portal', 'web');
        $migration = require database_path('migrations/2026_10_02_120100_deploy_workday_permissions.php');
        $migration->up();
        $this->assertTrue(Role::findByName('Superuser', 'web')->hasPermissionTo('workday.manage_settings'));
        $this->assertFalse(Role::findByName('Tech', 'web')->hasPermissionTo('workday.manage_settings'));
        $this->assertFalse(Role::findByName('Portal', 'web')->hasPermissionTo('workday.view_own'));
        Role::findByName('Superuser', 'web')->revokePermissionTo('workday.confirm_own');
        Role::findByName('Tech', 'web')->revokePermissionTo('workday.manage_own');
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->assertFalse(Role::findByName('Superuser', 'web')->hasPermissionTo('workday.confirm_own'));
        $this->assertFalse(Role::findByName('Tech', 'web')->hasPermissionTo('workday.manage_own'));
        $this->assertTrue(Role::findByName('Sales', 'web')->hasPermissionTo('workday.manage_own'));
        $this->assertFalse(Role::findByName('Sales', 'web')->hasPermissionTo('workday.manage_settings'));
    }

    public function test_real_bearer_authentication_and_one_sided_date_filter(): void
    {
        $this->save()->assertOk();
        $token = $this->worker->createToken('Synthetic API authentication', ['workdays.read']);
        app('auth')->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson('/api/v1/workdays?to=2026-10-01')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_browser_stale_save_has_a_clear_reload_path(): void
    {
        $this->save()->assertOk();
        $this->actingAs($this->worker, 'web')->put('/tech/workdays/2026-10-01/draft',
            $this->payload() + ['request_key' => (string) Str::uuid()])
            ->assertConflict()->assertSee('Reload workdays')->assertSee('The record changed.');
    }

    public function test_my_day_link_requires_enabled_workday_and_own_permission(): void
    {
        Permission::findOrCreate('warroom.view', 'web');
        $this->worker->givePermissionTo('warroom.view');
        $this->actingAs($this->worker, 'web')->get('/tech/my-day')->assertOk()->assertSee('My workdays');
        config(['workday.enabled' => false]);
        $this->get('/tech/my-day')->assertOk()->assertDontSee('My workdays');
    }
}
