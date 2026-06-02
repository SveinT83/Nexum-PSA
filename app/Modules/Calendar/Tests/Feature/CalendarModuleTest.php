<?php

namespace App\Modules\Calendar\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Calendar\Actions\CheckAvailability;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Actions\FindAvailableSlots;
use App\Modules\Calendar\Controllers\Admin\CalendarSettingsController;
use App\Modules\Calendar\Controllers\Tech\CalendarController;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarAccess;
use App\Modules\Calendar\Models\CalendarAvailabilityRule;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Queries\CalendarOverlayQuery;
use App\Modules\UserManagement\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CalendarModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);
        Role::create(['name' => 'Admin']);

        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Ada Tech']);
        $this->tech->assignRole('Tech');
    }

    #[Test]
    public function calendar_routes_are_owned_by_calendar_module(): void
    {
        $this->assertSame(CalendarController::class.'@index', Route::getRoutes()->getByName('tech.calendar.index')->getActionName());
        $this->assertSame(CalendarController::class.'@store', Route::getRoutes()->getByName('tech.calendar.events.store')->getActionName());
        $this->assertSame(CalendarSettingsController::class.'@index', Route::getRoutes()->getByName('tech.admin.settings.calendar')->getActionName());
    }

    #[Test]
    public function technician_can_open_calendar_and_get_personal_work_calendar(): void
    {
        $this->actingAs($this->tech)
            ->get(route('tech.calendar.index'))
            ->assertOk()
            ->assertSee('Calendar')
            ->assertSee('Search events')
            ->assertSee('Sort by')
            ->assertSee('card-header', false)
            ->assertSee('New')
            ->assertSee('Today')
            ->assertSee('Ada Tech work calendar');

        $this->assertDatabaseHas('calendars', [
            'type' => 'personal',
            'owner_type' => User::class,
            'owner_id' => $this->tech->id,
            'is_default' => true,
        ]);
    }

    #[Test]
    public function authenticated_api_user_can_manage_calendar_events(): void
    {
        Sanctum::actingAs($this->tech, ['calendar.read', 'calendar.create', 'calendar.update', 'calendar.delete']);

        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->tech);

        $this->getJson(route('api.v1.calendars.index'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $calendar->id);

        $createResponse = $this->postJson(route('api.v1.calendar.events.store'), [
            'calendar_id' => $calendar->id,
            'title' => 'API planning',
            'description' => 'Created by API test.',
            'starts_at' => '2026-06-02 09:00:00',
            'ends_at' => '2026-06-02 10:00:00',
            'timezone' => 'Europe/Oslo',
            'participants' => [
                ['name' => 'Client Contact', 'email' => 'client@example.test'],
            ],
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.title', 'API planning')
            ->assertJsonPath('data.calendar.id', $calendar->id)
            ->assertJsonPath('data.participants.0.email', 'client@example.test');

        $eventId = $createResponse->json('data.id');

        $this->getJson(route('api.v1.calendar.events.index', [
            'from' => '2026-06-02 00:00:00',
            'to' => '2026-06-03 00:00:00',
            'calendar_id' => $calendar->id,
        ]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $eventId)
            ->assertJsonPath('data.0.title', 'API planning');

        $this->patchJson(route('api.v1.calendar.events.update', $eventId), [
            'title' => 'API planning updated',
            'visibility' => 'public',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'API planning updated')
            ->assertJsonPath('data.visibility', 'public');

        $this->deleteJson(route('api.v1.calendar.events.destroy', $eventId))
            ->assertNoContent();

        $this->assertSoftDeleted('calendar_events', ['id' => $eventId]);
    }

    #[Test]
    public function calendar_read_api_token_cannot_create_events(): void
    {
        Sanctum::actingAs($this->tech, ['calendar.read']);

        $this->getJson(route('api.v1.calendars.index'))
            ->assertOk();

        $this->postJson(route('api.v1.calendar.events.store'), [
            'title' => 'Denied API event',
            'starts_at' => '2026-06-02 09:00:00',
            'ends_at' => '2026-06-02 10:00:00',
        ])->assertForbidden();
    }

    #[Test]
    public function technician_can_search_and_sort_calendar_events(): void
    {
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->tech);

        CalendarEvent::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'calendar_id' => $calendar->id,
            'title' => 'Beta deployment',
            'starts_at' => Carbon::parse('2026-05-18 09:00', 'Europe/Oslo')->utc(),
            'ends_at' => Carbon::parse('2026-05-18 10:00', 'Europe/Oslo')->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'confirmed',
            'transparency' => 'busy',
            'visibility' => 'public',
            'created_by' => $this->tech->id,
        ]);
        CalendarEvent::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'calendar_id' => $calendar->id,
            'title' => 'Alpha planning',
            'starts_at' => Carbon::parse('2026-05-18 11:00', 'Europe/Oslo')->utc(),
            'ends_at' => Carbon::parse('2026-05-18 12:00', 'Europe/Oslo')->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'tentative',
            'transparency' => 'busy',
            'visibility' => 'public',
            'created_by' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.calendar.index', [
                'view' => 'list',
                'date' => '2026-05-18',
                'event_sort' => 'title',
                'event_direction' => 'asc',
            ]))
            ->assertOk()
            ->assertSeeInOrder(['Alpha planning', 'Beta deployment']);

        $this->actingAs($this->tech)
            ->get(route('tech.calendar.index', [
                'view' => 'list',
                'date' => '2026-05-18',
                'event_search' => 'deployment',
            ]))
            ->assertOk()
            ->assertSee('Beta deployment')
            ->assertDontSee('Alpha planning');
    }

    #[Test]
    public function technician_can_create_calendar_event(): void
    {
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->tech);

        $this->actingAs($this->tech)
            ->post(route('tech.calendar.events.store'), [
                'calendar_id' => $calendar->id,
                'title' => 'Customer planning meeting',
                'starts_at' => '2026-05-18T09:00',
                'ends_at' => '2026-05-18T10:00',
                'timezone' => 'Europe/Oslo',
                'status' => 'confirmed',
                'transparency' => 'busy',
                'visibility' => 'public',
                'participants' => 'customer@example.test, colleague@example.test',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('calendar_events', [
            'calendar_id' => $calendar->id,
            'title' => 'Customer planning meeting',
            'timezone' => 'Europe/Oslo',
            'visibility' => 'public',
        ]);
        $this->assertDatabaseHas('calendar_participants', ['email' => 'customer@example.test']);
        $this->assertDatabaseHas('calendar_participants', ['email' => 'colleague@example.test']);
    }

    #[Test]
    public function technician_can_update_calendar_event(): void
    {
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->tech);
        $event = CalendarEvent::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'calendar_id' => $calendar->id,
            'title' => 'Draft meeting',
            'starts_at' => Carbon::parse('2026-05-18 09:00', 'Europe/Oslo')->utc(),
            'ends_at' => Carbon::parse('2026-05-18 10:00', 'Europe/Oslo')->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'tentative',
            'transparency' => 'tentative',
            'visibility' => 'default',
            'created_by' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->patch(route('tech.calendar.events.update', $event), [
                'calendar_id' => $calendar->id,
                'title' => 'Confirmed meeting',
                'starts_at' => '2026-05-18T10:00',
                'ends_at' => '2026-05-18T11:00',
                'timezone' => 'Europe/Oslo',
                'status' => 'confirmed',
                'transparency' => 'busy',
                'visibility' => 'public',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('calendar_events', [
            'id' => $event->id,
            'title' => 'Confirmed meeting',
            'status' => 'confirmed',
            'transparency' => 'busy',
            'visibility' => 'public',
        ]);
    }

    #[Test]
    public function recurring_events_are_expanded_in_calendar_range(): void
    {
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->tech);

        $this->actingAs($this->tech)
            ->post(route('tech.calendar.events.store'), [
                'calendar_id' => $calendar->id,
                'title' => 'Weekly planning',
                'starts_at' => '2026-05-04T09:00',
                'ends_at' => '2026-05-04T10:00',
                'timezone' => 'Europe/Oslo',
                'status' => 'confirmed',
                'transparency' => 'busy',
                'visibility' => 'public',
                'recurrence_frequency' => 'weekly',
                'recurrence_ends_at' => '2026-05-31',
            ])
            ->assertRedirect();

        $events = app(CalendarOverlayQuery::class)->eventsForRange(
            $this->tech,
            Carbon::parse('2026-05-18 00:00', 'Europe/Oslo'),
            Carbon::parse('2026-05-25 00:00', 'Europe/Oslo')
        );

        $this->assertCount(1, $events);
        $this->assertSame('Weekly planning', $events->first()['title']);
        $this->assertTrue($events->first()['is_recurring']);
        $this->assertSame('2026-05-18 07:00:00', $events->first()['starts_at']->toDateTimeString());
    }

    #[Test]
    public function recurring_events_block_free_busy_checks(): void
    {
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->tech);

        $this->actingAs($this->tech)
            ->post(route('tech.calendar.events.store'), [
                'calendar_id' => $calendar->id,
                'title' => 'Daily standup',
                'starts_at' => '2026-05-18T09:00',
                'ends_at' => '2026-05-18T09:30',
                'timezone' => 'Europe/Oslo',
                'status' => 'confirmed',
                'transparency' => 'busy',
                'visibility' => 'public',
                'recurrence_frequency' => 'daily',
                'recurrence_ends_at' => '2026-05-22',
            ])
            ->assertRedirect();

        $busyEvents = app(CheckAvailability::class)->busyEvents(
            [$calendar],
            Carbon::parse('2026-05-20 09:10', 'Europe/Oslo'),
            Carbon::parse('2026-05-20 09:20', 'Europe/Oslo')
        );

        $this->assertCount(1, $busyEvents);
        $this->assertSame('Daily standup', $busyEvents[0]->title);
        $this->assertFalse(app(CheckAvailability::class)->isFree(
            [$calendar],
            Carbon::parse('2026-05-20 09:10', 'Europe/Oslo'),
            Carbon::parse('2026-05-20 09:20', 'Europe/Oslo')
        ));
        $this->assertTrue(app(CheckAvailability::class)->isFree(
            [$calendar],
            Carbon::parse('2026-05-20 10:00', 'Europe/Oslo'),
            Carbon::parse('2026-05-20 10:30', 'Europe/Oslo')
        ));
    }

    #[Test]
    public function recurring_event_occurrence_can_be_cancelled_without_deleting_series(): void
    {
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->tech);

        $this->actingAs($this->tech)
            ->post(route('tech.calendar.events.store'), [
                'calendar_id' => $calendar->id,
                'title' => 'Weekly review',
                'starts_at' => '2026-05-04T09:00',
                'ends_at' => '2026-05-04T10:00',
                'timezone' => 'Europe/Oslo',
                'status' => 'confirmed',
                'transparency' => 'busy',
                'visibility' => 'public',
                'recurrence_frequency' => 'weekly',
                'recurrence_ends_at' => '2026-05-31',
            ])
            ->assertRedirect();

        $event = CalendarEvent::query()->where('title', 'Weekly review')->firstOrFail();

        $this->actingAs($this->tech)
            ->delete(route('tech.calendar.events.destroy', $event), [
                'scope' => 'event',
                'original_starts_at' => '2026-05-18T09:00',
            ])
            ->assertRedirect();

        $events = app(CalendarOverlayQuery::class)->eventsForRange(
            $this->tech,
            Carbon::parse('2026-05-01 00:00', 'Europe/Oslo'),
            Carbon::parse('2026-06-01 00:00', 'Europe/Oslo')
        );

        $this->assertDatabaseHas('calendar_event_exceptions', [
            'series_id' => $event->series_id,
            'exception_type' => 'cancelled',
        ]);
        $this->assertCount(3, $events);
        $this->assertFalse($events->contains(fn ($occurrence) => $occurrence['starts_at']->toDateTimeString() === '2026-05-18 07:00:00'));
    }

    #[Test]
    public function availability_finder_returns_slots_inside_working_hours(): void
    {
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->tech);
        CalendarEvent::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'calendar_id' => $calendar->id,
            'title' => 'Booked',
            'starts_at' => Carbon::parse('2026-05-18 09:00', 'Europe/Oslo')->utc(),
            'ends_at' => Carbon::parse('2026-05-18 10:00', 'Europe/Oslo')->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'confirmed',
            'transparency' => 'busy',
            'visibility' => 'public',
            'created_by' => $this->tech->id,
        ]);

        $slots = app(FindAvailableSlots::class)->handle(
            $this->tech,
            Carbon::parse('2026-05-18 08:00', 'Europe/Oslo'),
            Carbon::parse('2026-05-18 12:00', 'Europe/Oslo'),
            60,
            3
        );

        $this->assertSame('2026-05-18 06:00:00', $slots->first()['starts_at']->copy()->utc()->toDateTimeString());
        $this->assertFalse($slots->contains(fn ($slot) => $slot['starts_at']->format('H:i') === '09:00'));
    }

    #[Test]
    public function calendar_uses_user_preferences_for_defaults(): void
    {
        UserPreference::query()->create([
            'user_id' => $this->tech->id,
            'timezone' => 'America/New_York',
            'default_calendar_view' => 'month',
            'workday_start' => '09:00',
            'workday_end' => '17:00',
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.calendar.index'))
            ->assertOk()
            ->assertSee('America/New_York')
            ->assertSee('Month');

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $this->tech->id,
            'timezone' => 'America/New_York',
            'default_calendar_view' => 'month',
        ]);
    }

    #[Test]
    public function admin_can_share_calendar_with_role(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Admin');
        $calendar = Calendar::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Dispatch',
            'slug' => 'dispatch',
            'type' => 'team',
            'timezone' => 'Europe/Oslo',
        ]);
        $role = Role::where('name', 'Tech')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('tech.admin.settings.calendar.access.store', $calendar), [
                'subject_ref' => 'role:'.$role->id,
                'access_level' => 'editor',
                'can_view_private_details' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('calendar_access', [
            'calendar_id' => $calendar->id,
            'subject_type' => 'role',
            'subject_id' => $role->id,
            'access_level' => 'editor',
            'can_view_private_details' => true,
        ]);
        $this->assertTrue(app(CalendarOverlayQuery::class)->visibleCalendars($this->tech)->contains('id', $calendar->id));
    }

    #[Test]
    public function private_events_are_masked_for_other_technicians_but_still_block_availability(): void
    {
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Other Tech']);
        $other->assignRole('Tech');

        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->tech);
        CalendarEvent::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'calendar_id' => $calendar->id,
            'title' => 'Doctor appointment',
            'starts_at' => Carbon::parse('2026-05-18 11:00', 'Europe/Oslo')->utc(),
            'ends_at' => Carbon::parse('2026-05-18 12:00', 'Europe/Oslo')->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'confirmed',
            'transparency' => 'busy',
            'visibility' => 'private',
            'created_by' => $this->tech->id,
        ]);

        $events = app(CalendarOverlayQuery::class)->eventsForRange(
            $other,
            Carbon::parse('2026-05-18 00:00', 'Europe/Oslo'),
            Carbon::parse('2026-05-19 00:00', 'Europe/Oslo')
        );

        $this->assertSame('Busy', $events->first()['title']);
        $this->assertFalse($events->first()['details_visible']);
        $this->assertFalse(app(CheckAvailability::class)->isFree(
            [$calendar],
            Carbon::parse('2026-05-18 11:15', 'Europe/Oslo'),
            Carbon::parse('2026-05-18 11:45', 'Europe/Oslo')
        ));
    }

    #[Test]
    public function admin_can_create_shared_calendar(): void
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->post(route('tech.admin.settings.calendar.calendars.store'), [
                'name' => 'On-call',
                'type' => 'shift',
                'color' => '#0f766e',
                'timezone' => 'Europe/Oslo',
                'visibility_default' => 'public',
                'transparency_default' => 'busy',
                'is_visible_by_default' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('calendars', [
            'name' => 'On-call',
            'type' => 'shift',
            'is_visible_by_default' => true,
        ]);
    }
}
