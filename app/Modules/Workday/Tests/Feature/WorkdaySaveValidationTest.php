<?php

namespace App\Modules\Workday\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\UserManagement\Actions\UserWorkPlan;
use App\Modules\UserManagement\Support\UserProfileData;
use App\Modules\Workday\Actions\MutateWorkday;
use App\Modules\Workday\Models\Workday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The effective Save route must retain rejected input without storing a session copy. */
class WorkdaySaveValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        config(['workday.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-06 14:30', 'UTC'));
        CommonSetting::where('type', 'workday')->where('name', 'manual_workflow')
            ->update(['json' => json_encode(['enabled' => true, 'version' => 0, 'retention_years' => 3])]);
        $role = Role::findOrCreate('Tech', 'web');
        $role->givePermissionTo(['workday.view_own', 'workday.manage_own']);
        $this->worker = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->worker->assignRole($role);
        app(UserWorkPlan::class)->update($this->worker, [
            'revision' => app(UserWorkPlan::class)->read($this->worker)['revision'],
            'timezone' => 'UTC', 'working_hours' => UserProfileData::defaultWorkingHours(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function input(array $intervals, int $version = 0): array
    {
        return ['version' => $version, 'timezone' => 'UTC', 'description' => 'Synthetic private work',
            'intervals' => $intervals, 'breaks' => []];
    }

    private function assertModal($response, string $message, string $retained): void
    {
        $response->assertUnprocessable()->assertSee($message)->assertSee($retained)
            ->assertSessionMissing('_old_input');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertStringContainsString($message,
            $xpath->query('//*[@id="workday-entry-modal"]//*[@role="alert"]')->item(0)->textContent);
        $editor = $xpath->query('//section[@aria-label="Hourly workday"]')->item(0)->getAttribute('x-data');
        $this->assertStringContainsString('reopenEditor\\u0022:true', $editor);
        $this->assertStringContainsString($retained, $editor);
    }

    public function test_future_end_reopens_saved_day_modal_and_retry_at_current_minute_succeeds(): void
    {
        $intervals = [
            ['start' => '2026-10-06T09:00', 'end' => '2026-10-06T10:00'],
            ['start' => '2026-10-06T10:00', 'end' => '2026-10-06T11:00'],
            ['start' => '2026-10-06T11:00', 'end' => '2026-10-06T12:00'],
            ['start' => '2026-10-06T12:00', 'end' => '2026-10-06T12:30'],
            ['start' => '2026-10-06T12:30', 'end' => '2026-10-06T14:00'],
        ];
        $saved = app(MutateWorkday::class)->handle($this->worker, 'save', '2026-10-06',
            $this->input($intervals), (string) Str::uuid(), 'ui')['data'];
        $before = Workday::firstOrFail()->currentRevision->snapshot;
        $intervals[] = ['start' => '2026-10-06T14:00', 'end' => '2026-10-06T15:00', 'description' => 'Synthetic later activity'];
        $input = $this->input($intervals, $saved['version']) + ['editor_index' => 5, 'request_key' => (string) Str::uuid()];
        $this->actingAs($this->worker, 'web');
        $response = $this->put('/tech/workdays/2026-10-06/save', $input);
        $this->assertModal($response, 'Actual work must have ended before saving.', 'Synthetic later activity');
        $response->assertSee('14:30')->assertSee('UTC');
        $this->assertSame($before, Workday::firstOrFail()->currentRevision->snapshot);
        $this->assertDatabaseCount('workday_revisions', 1);
        $this->assertDatabaseCount('workday_mutation_receipts', 1);

        // A rejected save consumes neither its version nor its idempotency key.
        $input['intervals'][5]['end'] = '2026-10-06T14:30';
        $this->put('/tech/workdays/2026-10-06/save', $input)->assertRedirect();
        $snapshot = Workday::firstOrFail()->currentRevision->snapshot;
        $this->assertCount(6, $snapshot['intervals']);
        $this->assertSame('2026-10-06T14:30:00Z', $snapshot['intervals'][5]['end']);
        $this->assertSame(330, $snapshot['actual_minutes']);
    }

    public function test_future_date_reopens_new_day_without_creating_a_record(): void
    {
        $this->actingAs($this->worker, 'web');
        $input = $this->input([['start' => '2026-10-07T09:00', 'end' => '2026-10-07T10:00', 'description' => 'Synthetic tomorrow']])
            + ['editor_index' => 0, 'request_key' => (string) Str::uuid()];
        $response = $this->put('/tech/workdays/2026-10-07/save', $input);
        $this->assertModal($response, 'Actual time cannot be saved for a future date.', 'Synthetic tomorrow');
        $response->assertSee('2026-10-07')->assertSee('UTC');
        $this->assertDatabaseCount('workdays', 0);
        $this->assertDatabaseCount('workday_mutation_receipts', 0);
    }

    public function test_ordinary_save_validation_preserves_input_in_the_modal(): void
    {
        $this->actingAs($this->worker, 'web');
        $input = $this->input([
            ['start' => '2026-10-06T09:00', 'end' => '2026-10-06T10:00'],
            ['start' => '2026-10-06T09:45', 'end' => '2026-10-06T10:30', 'description' => 'Synthetic overlapping work'],
        ]) + ['editor_index' => 1, 'request_key' => (string) Str::uuid()];
        $this->assertModal($this->put('/tech/workdays/2026-10-06/save', $input),
            'Intervals and breaks must not overlap', 'Synthetic overlapping work');
        $this->assertDatabaseCount('workdays', 0);
    }

    public function test_personal_api_reports_timezone_error_and_accepts_completed_oslo_afternoon_time(): void
    {
        $token = $this->worker->createToken('Synthetic save validation', ['workdays.write', 'workdays.read'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => (string) Str::uuid()];
        $input = $this->input([['start' => '2026-10-06T14:00', 'end' => '2026-10-06T16:00']]);
        $this->putJson('/api/v1/workdays/2026-10-06/save', $input, $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('intervals.0.end')
            ->assertSee('UTC')->assertSee('14:30');
        $this->assertDatabaseCount('workdays', 0);

        $input['timezone'] = 'Europe/Oslo';
        $this->app['auth']->forgetGuards();
        $saved = $this->putJson('/api/v1/workdays/2026-10-06/save', $input, $headers)->assertOk()
            ->assertJsonPath('data.timezone', 'Europe/Oslo')
            ->assertJsonPath('data.current.snapshot.actual_minutes', 120)->json('data');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/workdays/'.$saved['id'], ['Authorization' => 'Bearer '.$token])->assertOk()
            ->assertJsonPath('data.current.snapshot.intervals.0.end', '2026-10-06T14:00:00Z');
        $this->assertSame(1, DB::table('workday_mutation_receipts')->count());
    }

    public function test_completed_oslo_evening_saves_and_reads_back_in_winter_and_summer_with_retry_safety(): void
    {
        foreach (['2026-10-29' => '19:45', '2026-07-29' => '18:45'] as $date => $utcNow) {
            Carbon::setTestNow(Carbon::parse($date.' '.$utcNow, 'UTC'));
            $token = $this->worker->createToken('Synthetic Oslo regression', ['workdays.write', 'workdays.read'])->plainTextToken;
            $headers = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => (string) Str::uuid()];
            $body = $this->input([['start' => $date.'T19:40', 'end' => $date.'T20:40']]);
            $body['timezone'] = 'Europe/Oslo';
            $this->app['auth']->forgetGuards();
            $saved = $this->putJson('/api/v1/workdays/'.$date.'/save', $body, $headers)->assertOk()
                ->assertJsonPath('data.timezone', 'Europe/Oslo')
                ->assertJsonPath('data.current.snapshot.actual_minutes', 60)->json('data');
            $endUtc = $date.'T'.($utcNow === '19:45' ? '19:40' : '18:40').':00Z';
            $this->app['auth']->forgetGuards();
            $this->getJson('/api/v1/workdays/'.$saved['id'], ['Authorization' => 'Bearer '.$token])
                ->assertOk()->assertJsonPath('data.current.snapshot.intervals.0.end', $endUtc);

            $this->app['auth']->forgetGuards();
            $this->assertSame($saved, $this->putJson('/api/v1/workdays/'.$date.'/save', $body, $headers)->assertOk()->json('data'));
            $day = Workday::where('work_date', $date)->firstOrFail();
            $before = $day->currentRevision->snapshot;
            $revisions = DB::table('workday_revisions')->count();
            $receipts = DB::table('workday_mutation_receipts')->count();
            $body['version'] = $saved['version'];
            $body['intervals'][0]['end'] = $date.'T20:46';
            $headers['Idempotency-Key'] = (string) Str::uuid();
            $this->app['auth']->forgetGuards();
            $rejected = $this->putJson('/api/v1/workdays/'.$date.'/save', $body, $headers)->assertUnprocessable()
                ->assertJsonValidationErrors('intervals.0.end');
            $this->assertStringContainsString('Europe/Oslo', $rejected->json('errors')['intervals.0.end'][0]);
            $this->assertStringContainsString('20:45', $rejected->json('errors')['intervals.0.end'][0]);
            $this->assertSame($before, $day->fresh()->currentRevision->snapshot);
            $this->assertSame($revisions, DB::table('workday_revisions')->count());
            $this->assertSame($receipts, DB::table('workday_mutation_receipts')->count());
            $body['intervals'][0]['end'] = $date.'T20:45';
            $this->app['auth']->forgetGuards();
            $this->putJson('/api/v1/workdays/'.$date.'/save', $body, $headers)->assertOk()
                ->assertJsonPath('data.current.snapshot.actual_minutes', 65);
        }
    }

    public function test_oslo_web_save_accepts_passed_local_end_and_rejects_future_end_without_partial_changes(): void
    {
        foreach (['2026-10-29' => '19:45', '2026-07-29' => '18:45'] as $date => $utcNow) {
            Carbon::setTestNow(Carbon::parse($date.' '.$utcNow, 'UTC'));
            $this->actingAs($this->worker, 'web');
            $input = $this->input([['start' => $date.'T19:40', 'end' => $date.'T20:40']])
                + ['request_key' => (string) Str::uuid()];
            $input['timezone'] = 'Europe/Oslo';
            $this->put('/tech/workdays/'.$date.'/save', $input)->assertRedirect();
            $day = Workday::where('work_date', $date)->firstOrFail();
            $this->assertSame(60, $day->currentRevision->snapshot['actual_minutes']);
            $before = $day->currentRevision->snapshot;
            $input['version'] = $day->version;
            $input['intervals'][0]['end'] = $date.'T20:46';
            $input['request_key'] = (string) Str::uuid();
            $response = $this->put('/tech/workdays/'.$date.'/save', $input);
            $response->assertUnprocessable()->assertSee('Europe/Oslo')->assertSee('20:45')
                ->assertSee('Actual work must have ended before saving.')->assertSessionMissing('_old_input');
            $this->assertSame($before, $day->fresh()->currentRevision->snapshot);
        }
    }

    public function test_personal_bearer_plan_and_saved_day_stay_bound_to_one_employee_despite_480_300_difference(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-29 19:45', 'UTC'));
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->assignRole('Tech');
        $tokens = [];
        foreach ([[$this->worker, '08:00', '16:00'], [$other, '09:00', '14:00']] as [$user, $start, $end]) {
            $hours = UserProfileData::defaultWorkingHours();
            $hours['thursday'] = ['enabled' => true, 'start' => $start, 'end' => $end];
            app(UserWorkPlan::class)->update($user, [
                'revision' => app(UserWorkPlan::class)->read($user)['revision'],
                'timezone' => 'Europe/Oslo', 'working_hours' => $hours,
            ]);
            $tokens[$user->id] = $user->createToken('Synthetic personal identity', ['workdays.read', 'workdays.write'])->plainTextToken;
        }
        foreach ([[$this->worker, 480], [$other, 300]] as [$user, $planned]) {
            $this->app['auth']->forgetGuards();
            $this->getJson('/api/v1/workdays/2026-10-29/entry', ['Authorization' => 'Bearer '.$tokens[$user->id]])
                ->assertOk()->assertJsonPath('data.planned_minutes', $planned)
                ->assertJsonPath('data.timezone', 'Europe/Oslo')->assertJsonPath('data.day', null);
        }
        $headers = ['Authorization' => 'Bearer '.$tokens[$other->id], 'Idempotency-Key' => (string) Str::uuid()];
        $body = $this->input([['start' => '2026-10-29T09:00', 'end' => '2026-10-29T14:00']]);
        $body['timezone'] = 'Europe/Oslo';
        $this->app['auth']->forgetGuards();
        $saved = $this->putJson('/api/v1/workdays/2026-10-29/save', $body, $headers)->assertOk()
            ->assertJsonPath('data.current.snapshot.actual_minutes', 300)->json('data');
        $this->assertSame($other->id, Workday::where('uuid', $saved['id'])->firstOrFail()->user_id);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/workdays/2026-10-29/entry', ['Authorization' => 'Bearer '.$tokens[$other->id]])
            ->assertOk()->assertJsonPath('data.day.id', $saved['id'])
            ->assertJsonPath('data.planned_minutes', 300)->assertJsonPath('data.day.current.snapshot.actual_minutes', 300);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/workdays/'.$saved['id'], ['Authorization' => 'Bearer '.$tokens[$this->worker->id]])->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/workdays/2026-10-29/entry', ['Authorization' => 'Bearer '.$tokens[$this->worker->id]])
            ->assertOk()->assertJsonPath('data.day', null)->assertJsonPath('data.planned_minutes', 480);
        $body['user_id'] = $this->worker->id;
        $headers['Idempotency-Key'] = (string) Str::uuid();
        $this->app['auth']->forgetGuards();
        $this->putJson('/api/v1/workdays/2026-10-29/save', $body, $headers)->assertUnprocessable()
            ->assertJsonValidationErrors('input');
        $this->assertDatabaseCount('workdays', 1);
        $this->assertDatabaseCount('workday_revisions', 1);
        $this->assertDatabaseCount('workday_mutation_receipts', 1);
    }

    public function test_422_reports_exact_payload_fields_without_creating_days_or_consuming_keys(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-29 19:45', 'UTC'));
        $token = $this->worker->createToken('Synthetic rejection diagnostics', ['workdays.read', 'workdays.write'])->plainTextToken;
        $body = $this->input([['start' => '2026-10-29T19:40', 'end' => '2026-10-29T20:40']]);
        $body['timezone'] = 'Europe/Oslo';
        $missingVersion = $body;
        unset($missingVersion['version']);
        $missingBreakFlag = $body;
        $missingBreakFlag['breaks'] = [['start' => '2026-10-29T20:00', 'end' => '2026-10-29T20:10']];
        $wrongOffset = $body;
        $wrongOffset['intervals'][0]['end'] = '2026-10-29T20:40+02:00';
        $headers = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => (string) Str::uuid()];
        foreach ([[$missingVersion, 'version'], [$missingBreakFlag, 'breaks.0.included'], [$wrongOffset, 'intervals.0.end'], [$body + ['user_id' => 999], 'input']] as [$invalid, $field]) {
            $this->app['auth']->forgetGuards();
            $this->putJson('/api/v1/workdays/2026-10-29/save', $invalid, $headers)
                ->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertDatabaseCount('workdays', 0);
            $this->assertDatabaseCount('workday_revisions', 0);
            $this->assertDatabaseCount('workday_mutation_receipts', 0);
        }
        $this->app['auth']->forgetGuards();
        $this->putJson('/api/v1/workdays/2026-10-29/save', $body, $headers)->assertOk();
        $this->assertDatabaseCount('workdays', 1);
        $this->assertDatabaseCount('workday_mutation_receipts', 1);
    }

    public function test_utc_profile_overrides_oslo_preferences_and_supported_plan_correction_fixes_new_day_input(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-29 19:45', 'UTC'));
        $this->worker->preferences()->create(['timezone' => 'Europe/Oslo']);
        $token = $this->worker->createToken('Synthetic conflicting timezone', ['workdays.read', 'workdays.write'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => (string) Str::uuid()];
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/workdays/2026-10-29/entry', ['Authorization' => 'Bearer '.$token])
            ->assertOk()->assertJsonPath('data.timezone', 'UTC');
        $body = $this->input([['start' => '2026-10-29T19:40', 'end' => '2026-10-29T20:40']]);
        $this->app['auth']->forgetGuards();
        $rejected = $this->putJson('/api/v1/workdays/2026-10-29/save', $body, $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('intervals.0.end');
        $this->assertStringContainsString('UTC is 2026-10-29 19:45', $rejected->json('errors')['intervals.0.end'][0]);
        $this->assertDatabaseCount('workdays', 0);
        $plan = app(UserWorkPlan::class)->read($this->worker);
        app(UserWorkPlan::class)->update($this->worker, [
            'revision' => $plan['revision'], 'timezone' => 'Europe/Oslo', 'working_hours' => $plan['working_hours'],
        ]);
        $body['timezone'] = 'Europe/Oslo';
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/workdays/2026-10-29/entry', ['Authorization' => 'Bearer '.$token])
            ->assertOk()->assertJsonPath('data.timezone', 'Europe/Oslo');
        $this->app['auth']->forgetGuards();
        $saved = $this->putJson('/api/v1/workdays/2026-10-29/save', $body, $headers)->assertOk()
            ->assertJsonPath('data.current.snapshot.actual_minutes', 60)->json('data');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/workdays/'.$saved['id'], ['Authorization' => 'Bearer '.$token])->assertOk()
            ->assertJsonPath('data.current.snapshot.intervals.0.end', '2026-10-29T19:40:00Z');
        $this->assertDatabaseCount('workday_mutation_receipts', 1);
    }
}
