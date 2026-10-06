<?php

namespace App\Modules\UserManagement\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Calendar\Actions\CheckAvailability;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Actions\FindAvailableSlots;
use App\Modules\Calendar\Models\CalendarAvailabilityRule;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Services\CalendarRecurrenceExpander;
use App\Modules\UserManagement\Actions\UpdateUserPreferences;
use App\Modules\UserManagement\Actions\UserWorkPlan;
use App\Modules\UserManagement\Models\UserPreference;
use App\Modules\UserManagement\Models\UserProfile;
use App\Modules\UserManagement\Support\UserProfileData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserWorkPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        config(['workday.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00', 'Europe/Oslo'));
        Role::findOrCreate('Tech', 'web');
        $this->worker = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->worker->assignRole('Tech');
        app(EnsureCalendarDefaults::class)->handle($this->worker);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function authenticate(array $abilities = ['users.work-plan.read', 'users.work-plan.update', 'calendar.work-plan.read', 'calendar.work-plan.write']): void
    {
        Sanctum::actingAs($this->worker, $abilities);
    }

    private function plan(): array
    {
        return app(UserWorkPlan::class)->read($this->worker);
    }

    private function payload(): array
    {
        return ['revision' => $this->plan()['revision'], 'timezone' => 'Europe/Oslo',
            'working_hours' => UserProfileData::defaultWorkingHours()];
    }

    private function block(): array
    {
        return [
            'request_id' => (string) Str::uuid(), 'title' => 'Education',
            'activity' => 'education', 'timezone' => 'Europe/Oslo',
            'starts_at' => '2026-10-05T08:00', 'ends_at' => '2026-10-05T16:00',
            'phone_duty_available' => false, 'blocks_booking' => true,
            'recurrence_frequency' => 'weekly', 'recurrence_ends_at' => '2026-12-21',
        ];
    }

    public function test_weekly_plan_has_api_and_browser_parity_without_identity_mutation(): void
    {
        $this->authenticate();
        $before = $this->worker->getAttributes();
        $data = $this->payload();
        $data['working_hours']['monday'] = ['enabled' => true, 'start' => '10:00', 'end' => '14:00'];
        $data['working_hours']['friday']['enabled'] = false;
        $saved = $this->patchJson('/api/v1/users/me/work-plan', $data)->assertOk()
            ->assertJsonPath('data.working_hours.monday.start', '10:00')
            ->assertJsonPath('data.working_hours.friday.enabled', false)->json('data');
        $this->getJson('/api/v1/users/me/work-plan')->assertOk()->assertJsonPath('data.revision', $saved['revision']);
        $this->actingAs($this->worker, 'web')->get('/tech/profile/work-plan')->assertOk()
            ->assertSee('Normal weekly hours')->assertSee('10:00');
        foreach (['name', 'email', 'password', 'status'] as $field) {
            $this->assertSame($before[$field], $this->worker->fresh()->getAttributes()[$field]);
        }
        $calendar = app(UserWorkPlan::class)->calendar($this->worker);
        $this->assertSame(7, $calendar->availabilityRules()->whereDate('effective_from', '2026-10-01')->count());
    }

    public function test_unrelated_preferences_do_not_change_custom_weekdays_timezone_or_rules(): void
    {
        app(UserWorkPlan::class)->update($this->worker, $this->payload());
        $before = $this->plan();
        $calendar = app(UserWorkPlan::class)->calendar($this->worker);
        $rules = $calendar->availabilityRules()->get()->toArray();
        app(UpdateUserPreferences::class)->handle($this->worker, [
            'timezone' => 'America/New_York', 'default_calendar_view' => 'month',
            'workday_start' => '11:00', 'workday_end' => '19:00', 'theme' => 'dark',
        ]);
        $this->assertSame($before['working_hours'], $this->plan()['working_hours']);
        $this->assertSame('Europe/Oslo', $calendar->fresh()->timezone);
        $this->assertSame('Europe/Oslo', $this->plan()['timezone']);
        $this->assertSame($rules, $calendar->availabilityRules()->get()->toArray());
    }

    public function test_legacy_values_are_previewed_and_unowned_rules_need_acknowledgement(): void
    {
        UserPreference::create(['user_id' => $this->worker->id, 'timezone' => 'Europe/Oslo',
            'default_calendar_view' => 'week', 'workday_start' => '10:00', 'workday_end' => '15:00']);
        $calendar = app(UserWorkPlan::class)->calendar($this->worker);
        $custom = CalendarAvailabilityRule::create(['calendar_id' => $calendar->id, 'user_id' => $this->worker->id,
            'timezone' => 'Europe/Oslo', 'weekday' => 1, 'starts_at_local' => '11:00', 'ends_at_local' => '13:00']);
        $this->assertSame('10:00', $this->plan()['working_hours']['monday']['start']);
        $this->assertSame('legacy_preferences', $this->plan()['origin']);
        $this->authenticate();
        $data = $this->payload();
        $this->patchJson('/api/v1/users/me/work-plan', $data)->assertUnprocessable()
            ->assertJsonValidationErrors('accept_calendar_conflicts');
        $this->assertSame(0, UserProfile::count());
        $this->patchJson('/api/v1/users/me/work-plan', $data + ['accept_calendar_conflicts' => true])->assertOk();
        $this->assertNull($custom->fresh()->metadata);
        $slots = app(FindAvailableSlots::class)->handle($this->worker,
            Carbon::parse('2026-10-05 08:00', 'Europe/Oslo'), Carbon::parse('2026-10-05 16:00', 'Europe/Oslo'), 60);
        $this->assertSame('11:00', $slots->first()['starts_at']->format('H:i'));
        $this->assertSame('13:00', $slots->last()['ends_at']->format('H:i'));
    }

    public function test_dated_revisions_and_disabled_days_survive_calendar_default_checks(): void
    {
        $first = $this->payload();
        $first['working_hours']['friday']['enabled'] = false;
        app(UserWorkPlan::class)->update($this->worker, $first);
        Carbon::setTestNow(Carbon::parse('2026-10-02 12:00', 'Europe/Oslo'));
        $second = $this->payload();
        $second['working_hours']['thursday']['start'] = '12:00';
        $second['working_hours']['friday']['enabled'] = false;
        app(UserWorkPlan::class)->update($this->worker, $second);
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->worker);
        $count = $calendar->availabilityRules()->count();
        app(UserWorkPlan::class)->update($this->worker, $this->payload());
        $this->assertSame($count, $calendar->availabilityRules()->count());
        // Restore the disabled Friday after the deliberate all-week payload above.
        $data = $this->payload();
        $data['working_hours']['friday']['enabled'] = false;
        app(UserWorkPlan::class)->update($this->worker, $data);
        app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->worker);
        $finder = app(FindAvailableSlots::class);
        $old = $finder->handle($this->worker, Carbon::parse('2026-10-01 00:00', 'Europe/Oslo'),
            Carbon::parse('2026-10-01 23:59', 'Europe/Oslo'), 60);
        $this->assertSame('08:00', $old->first()['starts_at']->format('H:i'));
        $this->assertCount(0, $finder->handle($this->worker, Carbon::parse('2026-10-02 00:00', 'Europe/Oslo'),
            Carbon::parse('2026-10-02 23:59', 'Europe/Oslo'), 60));
    }

    public function test_own_api_rejects_wrong_scopes_stale_revisions_and_account_fields(): void
    {
        $this->authenticate(['users.work-plan.read']);
        $this->patchJson('/api/v1/users/me/work-plan', $this->payload())->assertForbidden();
        $this->authenticate();
        $data = $this->payload();
        $this->patchJson('/api/v1/users/me/work-plan', $data + ['user_id' => 999])->assertUnprocessable();
        $this->patchJson('/api/v1/users/me/work-plan', $data + ['roles' => ['Superuser']])->assertUnprocessable();
        $this->patchJson('/api/v1/users/me/work-plan', $data)->assertOk();
        $this->patchJson('/api/v1/users/me/work-plan', $data)->assertConflict();
        config(['workday.enabled' => false]);
        $this->getJson('/api/v1/users/me/work-plan')->assertNotFound();
    }

    public function test_portal_system_and_inactive_identities_cannot_enter_employee_plan(): void
    {
        foreach ([['status' => User::STATUS_ACTIVE], ['status' => User::STATUS_ACTIVE, 'is_system_actor' => true],
            ['status' => User::STATUS_DISABLED]] as $attributes) {
            $user = User::factory()->create($attributes);
            if ($user->isSystemActor() || ! $user->isActive()) {
                $user->assignRole('Tech');
            }
            Sanctum::actingAs($user, ['*']);
            $this->getJson('/api/v1/users/me/work-plan')->assertForbidden();
        }
    }

    public function test_education_series_is_idempotent_and_can_modify_then_cancel_one_occurrence(): void
    {
        $this->authenticate();
        $input = $this->block();
        $block = $this->postJson('/api/v1/calendar/work-plan/blocks', $input)->assertOk()
            ->assertJsonPath('data.phone_duty_available', false)->json('data');
        $this->postJson('/api/v1/calendar/work-plan/blocks', $input)->assertOk()->assertJsonPath('data.id', $block['id']);
        $this->assertSame(1, CalendarEvent::count());
        $changed = array_merge($input, ['starts_at' => '2026-10-12T09:00', 'ends_at' => '2026-10-12T12:00',
            'version' => 1, 'scope' => 'event', 'occurrence_starts_at' => '2026-10-12T08:00:00+02:00',
            'recurrence_frequency' => 'none']);
        $this->patchJson('/api/v1/calendar/work-plan/blocks/'.$block['id'], $changed)->assertOk();
        $this->assertSame(2, CalendarEvent::count());
        $event = CalendarEvent::findOrFail($block['id']);
        $this->assertSame('2026-10-05', $event->starts_at->toDateString());
        $rows = app(CalendarRecurrenceExpander::class)->expand($event->series()->with(['events', 'exceptions'])->first(),
            Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $this->assertCount(3, $rows);
        $this->deleteJson('/api/v1/calendar/work-plan/blocks/'.$block['id'], [
            'version' => 2, 'scope' => 'event', 'occurrence_starts_at' => '2026-10-19T08:00:00+02:00',
        ])->assertOk();
        $this->assertSame(1, $event->series()->count());
        $this->assertSame(2, $event->series->exceptions()->count());
        $this->actingAs($this->worker, 'web')->get('/tech/profile/work-plan')->assertOk()
            ->assertSee('Education')->assertSee('No phone duty');
    }

    public function test_plan_blocks_respect_ownership_and_generic_calendar_edits_cannot_bypass_them(): void
    {
        $this->authenticate();
        $data = $this->postJson('/api/v1/calendar/work-plan/blocks', $this->block())->assertOk()->json('data');
        $this->authenticate(['*']);
        $this->deleteJson('/api/v1/calendar/events/'.$data['id'])->assertUnprocessable();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->assignRole('Tech');
        Sanctum::actingAs($other, ['*']);
        $this->deleteJson('/api/v1/calendar/work-plan/blocks/'.$data['id'], ['version' => 1, 'scope' => 'series'])->assertForbidden();
        $this->getJson('/api/v1/calendar/work-plan/blocks')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('confirmed', CalendarEvent::find($data['id'])->status);
    }

    public function test_overnight_and_timezone_changes_do_not_create_ambiguous_slots(): void
    {
        $data = $this->payload();
        $data['working_hours']['monday'] = ['enabled' => true, 'start' => '22:00', 'end' => '06:00'];
        $data['working_hours']['tuesday']['enabled'] = false;
        app(UserWorkPlan::class)->update($this->worker, $data);
        $slots = app(FindAvailableSlots::class)->handle($this->worker,
            Carbon::parse('2026-10-06 00:00', 'Europe/Oslo'), Carbon::parse('2026-10-06 08:00', 'Europe/Oslo'), 60, 100);
        $this->assertSame('00:00', $slots->first()['starts_at']->format('H:i'));
        $this->assertSame('06:00', $slots->last()['ends_at']->format('H:i'));
        $this->authenticate();
        $invalid = array_merge($this->block(), ['starts_at' => '2026-10-25T02:30', 'ends_at' => '2026-10-25T04:00',
            'recurrence_frequency' => 'none']);
        $this->postJson('/api/v1/calendar/work-plan/blocks', $invalid)->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $invalid['starts_at'] = '2027-03-28T02:30';
        $invalid['ends_at'] = '2027-03-28T04:00';
        $this->postJson('/api/v1/calendar/work-plan/blocks', $invalid)->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    }

    public function test_education_blocks_booking_without_creating_time_or_absence(): void
    {
        $this->authenticate();
        $this->postJson('/api/v1/calendar/work-plan/blocks', $this->block())->assertOk();
        $calendar = app(UserWorkPlan::class)->calendar($this->worker);
        $this->assertFalse(app(CheckAvailability::class)->isFree([$calendar],
            Carbon::parse('2026-10-12 10:00', 'Europe/Oslo'), Carbon::parse('2026-10-12 11:00', 'Europe/Oslo')));
        $this->assertDatabaseCount('task_time_entries', 0);
        $this->assertDatabaseCount('calendar_availability_overrides', 0);
        $this->assertDatabaseCount('user_profiles', 0);
    }

    public function test_recurring_wall_clock_hours_survive_dst_and_reject_a_later_fold(): void
    {
        $this->authenticate();
        $input = array_merge($this->block(), [
            'starts_at' => '2026-10-18T00:30', 'ends_at' => '2026-10-18T04:30',
            'recurrence_ends_at' => '2026-11-01',
        ]);
        $id = $this->postJson('/api/v1/calendar/work-plan/blocks', $input)->assertOk()->json('data.id');
        $event = CalendarEvent::findOrFail($id);
        $rows = app(CalendarRecurrenceExpander::class)->expand($event->series()->with(['events', 'exceptions'])->first(),
            Carbon::parse('2026-10-25 00:00', 'Europe/Oslo'), Carbon::parse('2026-10-26 00:00', 'Europe/Oslo'));
        $this->assertCount(1, $rows);
        $this->assertSame('04:30', $rows[0]['ends_at']->copy()->timezone('Europe/Oslo')->format('H:i'));
        $this->assertEquals(5, $rows[0]['starts_at']->diffInHours($rows[0]['ends_at']));
        $input['request_id'] = (string) Str::uuid();
        $input['starts_at'] = '2026-10-18T02:30';
        $this->postJson('/api/v1/calendar/work-plan/blocks', $input)->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    }

    public function test_profile_update_projects_calendar_hours_through_existing_security_boundary(): void
    {
        $hours = UserProfileData::defaultWorkingHours();
        $hours['monday']['start'] = '10:00';
        $this->actingAs($this->worker, 'web')->patch('/tech/profile', [
            'name' => $this->worker->name, 'email' => $this->worker->email,
            'timezone' => 'Europe/Oslo', 'working_hours' => $hours,
        ])->assertRedirect('/tech/profile');
        $calendar = app(UserWorkPlan::class)->calendar($this->worker);
        $this->assertTrue($calendar->availabilityRules()->where('weekday', 1)
            ->where('starts_at_local', '10:00')->whereDate('effective_from', '2026-10-01')->exists());
    }

    public function test_plan_block_request_id_conflicts_and_series_cancellation_are_explicit(): void
    {
        $this->authenticate();
        $input = $this->block();
        $data = $this->postJson('/api/v1/calendar/work-plan/blocks', $input)->assertOk()->json('data');
        $this->postJson('/api/v1/calendar/work-plan/blocks', array_merge($input, ['title' => 'Different']))
            ->assertConflict();
        $this->deleteJson('/api/v1/calendar/work-plan/blocks/'.$data['id'], [
            'version' => 1, 'scope' => 'series',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $calendar = app(UserWorkPlan::class)->calendar($this->worker);
        $this->assertTrue(app(CheckAvailability::class)->isFree([$calendar],
            Carbon::parse('2026-10-12 10:00', 'Europe/Oslo'), Carbon::parse('2026-10-12 11:00', 'Europe/Oslo')));
        $this->assertSame(1, CalendarEvent::count());
    }
}
