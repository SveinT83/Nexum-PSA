<?php

namespace App\Modules\Workday\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\UserManagement\Actions\UserWorkPlan;
use App\Modules\UserManagement\Support\UserProfileData;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Queries\WorkdayEditor;
use App\Modules\Workday\Support\ReminderEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkdayEntryTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        config(['workday.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-01 18:00', 'Europe/Oslo'));
        CommonSetting::where('type', 'workday')->where('name', 'manual_workflow')
            ->update(['json' => json_encode(['enabled' => true, 'version' => 0, 'retention_years' => 3])]);
        $role = Role::findOrCreate('Tech', 'web');
        $role->givePermissionTo(['workday.view_own', 'workday.manage_own', 'workday.confirm_own']);
        $this->worker = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->worker->assignRole($role);
        $this->actingAs($this->worker, 'web');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(array $overrides = [], string $timezone = 'Europe/Oslo'): void
    {
        $action = app(UserWorkPlan::class);
        $hours = array_replace(UserProfileData::defaultWorkingHours(), $overrides);
        $action->update($this->worker, ['revision' => $action->read($this->worker)['revision'],
            'timezone' => $timezone, 'working_hours' => $hours]);
    }

    public function test_index_opens_start_end_from_canonical_plan_without_creating_a_day(): void
    {
        $this->plan(['thursday' => ['enabled' => true, 'start' => '09:00', 'end' => '15:00']]);
        $before = DB::table('calendar_availability_rules')->count();
        $page = $this->get('/tech/workdays?work_date=2026-10-01')->assertOk()
            ->assertSee('type="datetime-local"', false)->assertSee('Start')->assertSee('End')
            ->assertSee('Adjust Start and End to the exact minutes you worked')->assertDontSee('Register workday')
            ->assertSee('Saved workdays');
        $this->assertSame('Europe/Oslo', $page->viewData('day')['timezone']);
        $this->assertSame('2026-10-01T09:00+02:00', $page->viewData('initialSnapshot')['intervals'][0]['start']);
        $this->assertNull($page->viewData('day')['id']);
        $this->assertSame(0, Workday::count());
        $this->assertSame(0, DB::table('workday_mutation_receipts')->count());
        $this->assertSame($before, DB::table('calendar_availability_rules')->count());
    }

    public function test_saved_data_takes_precedence_over_plan_and_first_save_needs_no_create_step(): void
    {
        $this->plan();
        $input = ['version' => 0, 'timezone' => 'Europe/Oslo', 'description' => 'Actual test work',
            'intervals' => [['start' => '2026-10-01T09:30', 'end' => '2026-10-01T14:00', 'description' => 'Test']],
            'request_key' => (string) Str::uuid()];
        $this->put('/tech/workdays/2026-10-01/draft', $input)->assertRedirect();
        $this->plan(['thursday' => ['enabled' => true, 'start' => '07:00', 'end' => '12:00']]);
        $page = $this->get('/tech/workdays?work_date=2026-10-01')->assertOk()->assertSee('Actual test work');
        $this->assertSame(1, $page->viewData('day')['version']);
        $this->assertSame(270, $page->viewData('day')['current']['snapshot']['actual_minutes']);
        $this->assertNull($page->viewData('day')['confirmed']);
        $this->get('/tech/workdays/create?work_date=2026-10-01')->assertOk()->assertSee('Actual test work');
        $this->assertSame(1, Workday::count());
    }

    public function test_unsaved_default_week_and_dates_before_effective_plan_do_not_invent_work(): void
    {
        $page = $this->get('/tech/workdays?work_date=2026-10-01')->assertOk();
        $this->assertSame('', $page->viewData('initialSnapshot')['intervals'][0]['start']);
        $this->assertSame('unknown', $page->viewData('planState'));
        $this->plan();
        $past = $this->get('/tech/workdays?work_date=2026-09-30')->assertOk();
        $this->assertSame('', $past->viewData('initialSnapshot')['intervals'][0]['start']);
        $weekend = $this->get('/tech/workdays?work_date=2026-10-04')->assertOk();
        $this->assertSame('empty', $weekend->viewData('planState'));
        $this->assertSame('', $weekend->viewData('initialSnapshot')['intervals'][0]['start']);
    }

    public function test_overnight_plan_and_absence_share_the_reminder_interval_source(): void
    {
        $this->plan(['thursday' => ['enabled' => true, 'start' => '22:00', 'end' => '06:00']]);
        $editor = app(WorkdayEditor::class)->forDate($this->worker, '2026-10-01');
        $this->assertSame('2026-10-01T23:00+02:00', $editor['initialSnapshot']['intervals'][0]['end']);
        $reminder = app(ReminderEligibility::class)->plan($this->worker, '2026-10-01', 'Europe/Oslo');
        $this->assertSame('2026-10-02T06:00:00+02:00', $reminder['end']->setTimezone('Europe/Oslo')->toIso8601String());
        $this->assertSame(0, Workday::count());
    }

    public function test_clock_change_picker_can_distinguish_both_occurrences_without_losing_saved_offset(): void
    {
        $this->plan(['sunday' => ['enabled' => true, 'start' => '01:00', 'end' => '04:00']]);
        $page = $this->get('/tech/workdays?work_date=2026-10-25')->assertOk()->assertSee('clock-change occurrence');
        $change = $page->viewData('clockChanges')[0];
        $this->assertSame('2026-10-25T02:00', $change['from']);
        $this->assertSame('2026-10-25T03:00', $change['until']);
        $this->assertSame(['+02:00', '+01:00'], $change['offsets']);
        $this->put('/tech/workdays/2026-10-25/draft', ['version' => 0, 'timezone' => 'Europe/Oslo',
            'description' => 'Clock change test', 'request_key' => (string) Str::uuid(),
            'intervals' => [['start' => '2026-10-25T02:15+02:00', 'end' => '2026-10-25T02:45+01:00']]])
            ->assertRedirect();
        $saved = $this->get('/tech/workdays?work_date=2026-10-25')->assertOk();
        $this->assertSame(90, $saved->viewData('day')['current']['snapshot']['actual_minutes']);
        $this->assertStringContainsString('2026-10-25T02:15+02:00', $saved->getContent());
        $this->assertStringContainsString('2026-10-25T02:45+01:00', $saved->getContent());
    }

    public function test_entry_keeps_owner_and_view_only_permissions_and_api_list_contract(): void
    {
        $this->plan();
        $this->get('/tech/workdays?work_date=invalid')->assertRedirect();
        $role = Role::findOrCreate('WorkdayReadOnly', 'web');
        $role->givePermissionTo('workday.view_own');
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->assignRole($role);
        $this->actingAs($viewer, 'web')->get('/tech/workdays?work_date=2026-10-01')->assertOk()
            ->assertDontSee('Save draft')->assertSee('No work saved for this date');
        $this->actingAs($this->worker, 'web');
        $token = $this->worker->createToken('Synthetic list contract', ['workdays.read']);
        $this->getJson('/api/v1/workdays', ['Authorization' => 'Bearer '.$token->plainTextToken])
            ->assertOk()->assertJsonPath('total', 0)->assertJsonMissingPath('initialSnapshot');
    }

    public function test_existing_profile_hours_work_without_creating_a_calendar_and_do_not_invent_history(): void
    {
        $hours = UserProfileData::defaultWorkingHours();
        $hours['thursday'] = ['enabled' => true, 'start' => '10:00', 'end' => '14:00'];
        $this->worker->profile()->updateOrCreate(['user_id' => $this->worker->id],
            ['timezone' => 'Europe/Oslo', 'working_hours' => $hours]);
        $page = $this->get('/tech/workdays?work_date=2026-10-01')->assertOk();
        $this->assertSame('2026-10-01T10:00+02:00', $page->viewData('initialSnapshot')['intervals'][0]['start']);
        $this->assertSame('2026-10-01T11:00+02:00', $page->viewData('initialSnapshot')['intervals'][0]['end']);
        $this->assertNull(app(UserWorkPlan::class)->calendar($this->worker));
        $this->assertSame(0, Workday::count());
        $past = $this->get('/tech/workdays?work_date=2026-09-30')->assertOk();
        $this->assertSame('unknown', $past->viewData('planState'));
        $this->assertSame('2026-10-01T14:00:00+02:00',
            app(ReminderEligibility::class)->plan($this->worker, '2026-10-01', 'Europe/Oslo')['end']->setTimezone('Europe/Oslo')->toIso8601String());
    }

    public function test_partial_absence_splits_entry_suggestions_and_full_absence_leaves_manual_entry_blank(): void
    {
        $this->plan();
        $this->worker->givePermissionTo(['workday.absence_manage_own', 'workday.absence_view_own']);
        $absence = app(\App\Modules\Workday\Actions\MutateAbsence::class)->handle($this->worker, 'create', null,
            ['version' => 0, 'timezone' => 'Europe/Oslo', 'category' => 'other', 'mode' => 'partial',
                'starts_at' => '2026-10-01T10:00', 'ends_at' => '2026-10-01T12:00'],
            (string) Str::uuid(), 'ui');
        $page = $this->get('/tech/workdays?work_date=2026-10-01')->assertOk();
        $ranges = $page->viewData('initialSnapshot')['intervals'];
        $this->assertCount(1, $ranges);
        $this->assertSame('2026-10-01T09:00+02:00', $ranges[0]['end']);
        $this->assertSame(360, $page->viewData('timeline')['planned_minutes']);
        $this->assertCount(2, app(ReminderEligibility::class)->plan($this->worker, '2026-10-01', 'Europe/Oslo')['intervals']);
        app(\App\Modules\Workday\Actions\MutateAbsence::class)->handle($this->worker, 'update', $absence['data']['id'],
            ['version' => $absence['data']['version'], 'timezone' => 'Europe/Oslo', 'category' => 'other', 'mode' => 'full_day',
                'start_date' => '2026-10-01', 'end_date' => '2026-10-01'], (string) Str::uuid(), 'ui');
        $full = $this->get('/tech/workdays?work_date=2026-10-01')->assertOk();
        $this->assertSame('', $full->viewData('initialSnapshot')['intervals'][0]['start']);
        $this->assertSame('empty', $full->viewData('planState'));
        $this->assertNull(app(ReminderEligibility::class)->plan($this->worker, '2026-10-01', 'Europe/Oslo'));
        $this->assertSame(0, Workday::count());
    }
}
