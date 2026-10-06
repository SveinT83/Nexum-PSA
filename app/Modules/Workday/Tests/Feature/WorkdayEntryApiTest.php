<?php

namespace App\Modules\Workday\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Models\AiWorkloadTokenBinding;
use App\Modules\UserManagement\Actions\UserWorkPlan;
use App\Modules\UserManagement\Support\UserProfileData;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Queries\WorkdayEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Real personal bearer requests: the future MCP client needs no browser session or shared actor. */
class WorkdayEntryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['workday.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-01 18:00', 'Europe/Oslo'));
        CommonSetting::where('type', 'workday')->where('name', 'manual_workflow')
            ->update(['json' => json_encode(['enabled' => true, 'version' => 0, 'retention_years' => 3])]);
        $role = Role::findOrCreate('Tech', 'web');
        $role->givePermissionTo(['workday.view_own', 'workday.manage_own', 'workday.confirm_own',
            'workday.absence_view_own', 'workday.absence_manage_own']);
        $this->worker = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->worker->assignRole($role);
        $this->token = $this->worker->createToken('Synthetic minute-entry client',
            ['workdays.read', 'workdays.write', 'workdays.confirm', 'workday-absences.write'])->plainTextToken;
        $this->plan();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(array $changes = [], string $timezone = 'Europe/Oslo'): void
    {
        $hours = UserProfileData::defaultWorkingHours();
        $hours['thursday'] = ['enabled' => true, 'start' => '09:00', 'end' => '16:00'];
        app(UserWorkPlan::class)->update($this->worker, [
            'revision' => app(UserWorkPlan::class)->read($this->worker)['revision'],
            'timezone' => $timezone, 'working_hours' => array_replace($hours, $changes),
        ]);
    }

    private function api(string $method, string $path, array $body = [], ?string $key = null, ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        $headers = ['Authorization' => 'Bearer '.($token ?? $this->token)];
        if ($key !== null) $headers['Idempotency-Key'] = $key;

        return $this->json($method, '/api/v1/'.$path, $body, $headers);
    }

    private function draft(array $intervals, int $version = 0, array $breaks = []): array
    {
        return ['version' => $version, 'timezone' => 'Europe/Oslo', 'description' => 'Synthetic API day',
            'intervals' => $intervals, 'breaks' => $breaks];
    }

    public function test_date_entry_matches_calendar_without_creating_records_or_mixing_suggestions_with_actuals(): void
    {
        $before = [DB::table('calendars')->count(), DB::table('calendar_availability_rules')->count()];
        $response = $this->api('GET', 'workdays/2026-10-01/entry')->assertOk()
            ->assertJsonPath('data.day', null)->assertJsonPath('data.version', 0)
            ->assertJsonPath('data.timezone', 'Europe/Oslo')->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.requires_correction', false)->assertJsonPath('data.planned_minutes', 420);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $context = $response->json('data');
        $timeline = app(WorkdayEditor::class)->forDate($this->worker, '2026-10-01')['timeline'];
        $this->assertSame($timeline['selection'], $context['suggested_interval']);
        $this->assertSame($timeline['planned_intervals'], $context['planned_intervals']);
        $this->assertSame($context['planned_intervals'], $context['available_intervals']);
        $this->assertSame('2026-10-01T09:00+02:00', $context['suggested_interval']['start']);
        $this->assertSame('2026-10-01T10:00+02:00', $context['suggested_interval']['end']);
        $this->assertSame($before, [DB::table('calendars')->count(), DB::table('calendar_availability_rules')->count()]);
        $this->assertDatabaseCount('workdays', 0);
        $this->assertDatabaseCount('workday_revisions', 0);
        $this->api('GET', 'workdays/2026-09-29/entry')->assertOk()
            ->assertJsonPath('data.plan_state', 'unknown')->assertJsonPath('data.suggested_interval', null);
    }

    public function test_minute_create_add_edit_remove_retry_overlap_and_stale_write_keep_the_saved_day_consistent(): void
    {
        $path = 'workdays/2026-10-01/draft';
        $first = ['start' => '2026-10-01T09:00+02:00', 'end' => '2026-10-01T09:45+02:00', 'description' => '45-minute meeting'];
        $body = $this->draft([$first]);
        $key = (string) Str::uuid();
        $one = $this->api('PUT', $path, $body, $key)->assertOk()->assertJsonPath('data.current.snapshot.actual_minutes', 45)->json();
        $this->assertSame($one, $this->api('PUT', $path, $body, $key)->assertOk()->json());
        $this->assertDatabaseCount('workday_revisions', 1);
        $this->api('GET', 'workdays/2026-10-01/entry')->assertOk()
            ->assertJsonPath('data.day.id', $one['data']['id'])
            ->assertJsonPath('data.suggested_interval.start', '2026-10-01T09:45+02:00')
            ->assertJsonPath('data.suggested_interval.end', '2026-10-01T10:45+02:00');

        $second = ['start' => '2026-10-01T11:00+02:00', 'end' => '2026-10-01T12:30+02:00', 'description' => '90-minute work'];
        $breaks = [['start' => '2026-10-01T11:45+02:00', 'end' => '2026-10-01T12:00+02:00', 'included' => false]];
        $two = $this->api('PUT', $path, $this->draft([$first, $second], 1, $breaks), (string) Str::uuid())
            ->assertOk()->assertJsonPath('data.current.snapshot.actual_minutes', 120)->json('data');
        $first['start'] = '2026-10-01T09:15+02:00';
        $first['end'] = '2026-10-01T10:00+02:00';
        $editBody = $this->draft([$first, $second], 2, $breaks);
        $editKey = (string) Str::uuid();
        $edited = $this->api('PUT', $path, $editBody, $editKey)->assertOk()->json('data');
        $this->assertSame($two['current']['snapshot']['intervals'][1], $edited['current']['snapshot']['intervals'][1]);
        $this->assertSame($two['current']['snapshot']['breaks'], $edited['current']['snapshot']['breaks']);
        $this->assertSame(45, $edited['current']['snapshot']['intervals'][0]['minutes']);
        $invalid = $editBody;
        $invalid['version'] = 3;
        $invalid['intervals'][0]['end'] = '2026-10-01T11:01+02:00';
        $this->api('PUT', $path, $invalid, (string) Str::uuid())->assertUnprocessable();
        $this->api('PUT', $path, $editBody, (string) Str::uuid())->assertConflict();
        $invalid['version'] = 2;
        $this->api('PUT', $path, $invalid, $editKey)->assertConflict();
        $this->assertDatabaseCount('workday_revisions', 3);
        $this->assertSame($edited, $this->api('GET', 'workdays/'.$edited['id'])->assertOk()->json('data'));

        // Replacement removes exactly the omitted interval, keeping the remaining work and its break.
        $remaining = $this->api('PUT', $path, $this->draft([$second], 3, $breaks), (string) Str::uuid())
            ->assertOk()->assertJsonPath('data.current.snapshot.actual_minutes', 75)->json('data');
        $this->assertCount(1, $remaining['current']['snapshot']['intervals']);
        $this->api('PUT', $path, $this->draft([], 4), (string) Str::uuid())->assertUnprocessable();
        $this->api('GET', 'workdays/'.$remaining['id'].'/history')->assertOk()->assertJsonPath('total', 4);
        $this->assertDatabaseCount('ticket_time_entries', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_save_context_edits_legacy_confirmed_time_while_draft_api_preserves_its_contract_and_timezone(): void
    {
        $body = $this->draft([['start' => '2026-10-01T09:00', 'end' => '2026-10-01T09:45']]);
        $day = $this->api('PUT', 'workdays/2026-10-01/draft', $body, (string) Str::uuid())->assertOk()->json('data');
        $preview = $this->api('POST', 'workdays/'.$day['id'].'/preview', ['version' => 1], (string) Str::uuid())->assertOk()->json('preview');
        $confirmed = $this->api('POST', 'workdays/'.$day['id'].'/confirm',
            ['version' => 1, 'preview_token' => $preview['token'], 'confirmed' => true], (string) Str::uuid())->assertOk()->json('data');
        $this->plan(timezone: 'UTC');
        $this->api('GET', 'workdays/2026-10-01/entry')->assertOk()
            ->assertJsonPath('data.can_edit', true)->assertJsonPath('data.requires_correction', false)
            ->assertJsonStructure(['data' => ['suggested_interval' => ['start', 'end']]])->assertJsonPath('data.timezone', 'Europe/Oslo');
        $body['version'] = $confirmed['version'];
        $this->api('PUT', 'workdays/2026-10-01/draft', $body, (string) Str::uuid())->assertConflict();
        $correction = $this->api('POST', 'workdays/'.$day['id'].'/corrections',
            ['version' => $confirmed['version'], 'reason' => 'Synthetic correction'], (string) Str::uuid())->assertOk()->json('data');
        $this->api('GET', 'workdays/2026-10-01/entry')->assertOk()
            ->assertJsonPath('data.can_edit', true)->assertJsonPath('data.requires_correction', false)
            ->assertJsonPath('data.version', $correction['version'])
            ->assertJsonPath('data.day.confirmed.id', $confirmed['confirmed']['id']);
    }

    public function test_absence_and_adjacent_overnight_work_shape_suggestions_without_exposing_absence_reasons(): void
    {
        $night = $this->draft([['start' => '2026-09-30T22:00', 'end' => '2026-10-01T10:00']]);
        $this->api('PUT', 'workdays/2026-09-30/draft', $night, (string) Str::uuid())->assertOk();
        $this->api('POST', 'workday-absences', ['version' => 0, 'timezone' => 'Europe/Oslo',
            'category' => 'sickness', 'mode' => 'partial', 'starts_at' => '2026-10-01T10:00',
            'ends_at' => '2026-10-01T10:30'], (string) Str::uuid())->assertOk();
        $response = $this->api('GET', 'workdays/2026-10-01/entry')->assertOk()
            ->assertJsonPath('data.day', null)->assertJsonPath('data.planned_minutes', 390)
            ->assertJsonPath('data.suggested_interval.start', '2026-10-01T10:30+02:00')
            ->assertJsonPath('data.suggested_interval.end', '2026-10-01T11:30+02:00');
        $this->assertNotEmpty($response->json('data.reserved_intervals'));
        $this->assertStringNotContainsString('sickness', $response->getContent());
        $this->assertDatabaseCount('workdays', 1);
    }

    public function test_repeated_hour_uses_explicit_offsets_and_exact_elapsed_minutes(): void
    {
        $this->plan(['sunday' => ['enabled' => true, 'start' => '01:00', 'end' => '04:00']]);
        $this->api('GET', 'workdays/2026-10-25/entry')->assertOk()->assertJsonPath('data.planned_minutes', 240);
        $this->api('PUT', 'workdays/2026-10-25/draft', $this->draft([
            ['start' => '2026-10-25T01:00+02:00', 'end' => '2026-10-25T02:00+02:00'],
        ]), (string) Str::uuid())->assertOk();
        $this->api('GET', 'workdays/2026-10-25/entry')->assertOk()
            ->assertJsonPath('data.suggested_interval.start', '2026-10-25T02:00+02:00')
            ->assertJsonPath('data.suggested_interval.end', '2026-10-25T02:00+01:00');
    }

    public function test_read_scope_and_user_permission_are_both_reflected_in_capability_and_foreign_days_stay_private(): void
    {
        $day = $this->api('PUT', 'workdays/2026-10-01/draft', $this->draft([
            ['start' => '2026-10-01T09:00', 'end' => '2026-10-01T09:45'],
        ]), (string) Str::uuid())->assertOk()->json('data');
        $readToken = $this->worker->createToken('Synthetic read-only', ['workdays.read'])->plainTextToken;
        $this->api('GET', 'workdays/2026-10-01/entry', token: $readToken)->assertOk()
            ->assertJsonPath('data.can_edit', false)->assertJsonPath('data.suggested_interval', null);
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->givePermissionTo('workday.view_own');
        $otherToken = $other->createToken('Synthetic other worker', ['workdays.read', 'workdays.write'])->plainTextToken;
        $this->api('GET', 'workdays/2026-10-01/entry', token: $otherToken)->assertOk()
            ->assertJsonPath('data.day', null)->assertJsonPath('data.can_edit', false)->assertJsonPath('data.version', 0);
        $this->api('GET', 'workdays/'.$day['id'], token: $otherToken)->assertNotFound();
        $this->api('GET', 'workdays/2026-10-01/entry?user_id='.$this->worker->id, token: $otherToken)->assertUnprocessable();
        $other->revokePermissionTo('workday.view_own');
        $this->api('GET', 'workdays/2026-10-01/entry', token: $otherToken)->assertForbidden();
    }

    public function test_invalid_expired_and_disabled_context_is_rejected_without_retained_copies(): void
    {
        $this->api('GET', 'workdays/2026-02-30/entry')->assertUnprocessable();
        $this->api('GET', 'workdays/2023-09-30/entry')->assertNotFound();
        $this->api('GET', 'workdays/2023-10-01/entry')->assertOk();
        $day = $this->api('PUT', 'workdays/2026-10-01/draft', $this->draft([
            ['start' => '2026-10-01T09:00', 'end' => '2026-10-01T09:45'],
        ]), (string) Str::uuid())->assertOk()->json('data');
        Carbon::setTestNow(Carbon::parse($day['expires_at']));
        $this->api('GET', 'workdays/2026-10-01/entry')->assertNotFound();
        config(['workday.enabled' => false]);
        $this->api('GET', 'workdays/2029-10-02/entry')->assertNotFound();
        $this->assertDatabaseCount('workdays', 1);
    }

    public function test_coordinator_wildcard_token_cannot_read_employee_entry_context(): void
    {
        $token = $this->worker->createToken('Synthetic coordinator', ['*']);
        $profile = AiWorkloadProfile::create(['name' => 'Synthetic coordinator', 'slug' => 'entry-api-coordinator',
            'purpose' => 'Identity isolation', 'processing_mode' => 'local_only', 'maximum_data_profile' => 'pseudonymized',
            'abilities' => ['worklog.read'], 'is_approved' => true, 'is_active' => true, 'expires_at' => now()->addMonth(),
            'approved_by' => $this->worker->id, 'approved_at' => now(), 'created_by' => $this->worker->id]);
        AiWorkloadTokenBinding::create(['personal_access_token_id' => $token->accessToken->id, 'ai_workload_profile_id' => $profile->id,
            'expires_at' => now()->addWeek(), 'allowed_networks' => [], 'requests_per_minute' => 30, 'created_by' => $this->worker->id]);
        $this->api('GET', 'workdays/2026-10-01/entry', token: $token->plainTextToken)->assertForbidden();
        $this->assertSame(0, Workday::count());
    }
}
