<?php

namespace App\Modules\Calendar\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Calendar\Actions\CheckAvailability;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Actions\FindAvailableSlots;
use App\Modules\Calendar\Controllers\Admin\CalendarSettingsController;
use App\Modules\Calendar\Controllers\Tech\CalendarController;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Queries\CalendarOverlayQuery;
use App\Modules\UserManagement\Models\UserPreference;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use App\Modules\WorkContext\Support\WorkContextType;
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
            ->assertJsonPath('data.0.id', $calendar->id)
            ->assertJsonPath('data.0.owner_kind', 'user')
            ->assertJsonPath('data.0.owner_label', 'Ada Tech')
            ->assertJsonPath('data.0.owner_badge', 'AT')
            ->assertJsonPath('data.0.calendar_type', 'personal')
            ->assertJsonPath('data.0.ownership_group', 'mine')
            ->assertJsonPath('data.0.is_owned_by_viewer', true);

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
            ->assertJsonPath('data.work_context.type', WorkContextType::INTERNAL)
            ->assertJsonPath('data.participants.0.email', 'client@example.test');

        $eventId = $createResponse->json('data.id');

        $this->getJson(route('api.v1.calendar.events.index', [
            'from' => '2026-06-02 00:00:00',
            'to' => '2026-06-03 00:00:00',
            'calendar_id' => $calendar->id,
        ]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $eventId)
            ->assertJsonPath('data.0.work_context_type', WorkContextType::INTERNAL)
            ->assertJsonPath('data.0.title', 'API planning')
            ->assertJsonPath('data.0.owner_label', 'Ada Tech')
            ->assertJsonPath('data.0.owner_badge', 'AT')
            ->assertJsonPath('data.0.ownership_group', 'mine')
            ->assertJsonPath('data.0.is_owned_by_viewer', true);

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
            'work_context_id' => app(ResolveWorkContext::class)->internal()->id,
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
    public function availability_finder_accepts_explicit_weekly_windows_and_still_excludes_conflicts(): void
    {
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->tech);
        CalendarEvent::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'calendar_id' => $calendar->id,
            'title' => 'Busy inside explicit window',
            'starts_at' => Carbon::parse('2026-05-18 10:00', 'Europe/Oslo')->utc(),
            'ends_at' => Carbon::parse('2026-05-18 11:00', 'Europe/Oslo')->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'confirmed',
            'transparency' => 'busy',
            'visibility' => 'public',
            'created_by' => $this->tech->id,
        ]);

        $slots = app(FindAvailableSlots::class)->handle(
            $this->tech,
            Carbon::parse('2026-05-18 08:00', 'Europe/Oslo'),
            Carbon::parse('2026-05-18 13:00', 'Europe/Oslo'),
            60,
            10,
            [
                'timezone' => 'Europe/Oslo',
                'weekdays' => [
                    1 => [
                        ['start' => '10:00', 'end' => '12:00'],
                    ],
                ],
            ],
        );

        $this->assertCount(1, $slots);
        $this->assertSame('11:00', $slots->first()['starts_at']->format('H:i'));
        $this->assertFalse($slots->contains(
            fn (array $slot): bool => $slot['starts_at']->format('H:i') === '10:00',
        ));
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
    public function ownership_metadata_uses_calendar_owner_instead_of_event_creator(): void
    {
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Bob Builder']);
        $viewer->assignRole('Tech');
        $personalCalendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->tech);
        $personalEvent = CalendarEvent::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'calendar_id' => $personalCalendar->id,
            'title' => 'Created by another technician',
            'starts_at' => Carbon::parse('2026-05-18 09:00', 'Europe/Oslo')->utc(),
            'ends_at' => Carbon::parse('2026-05-18 10:00', 'Europe/Oslo')->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'confirmed',
            'transparency' => 'busy',
            'visibility' => 'public',
            'created_by' => $viewer->id,
        ]);
        $teamCalendar = Calendar::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Dispatch team',
            'slug' => 'dispatch-team-metadata-test',
            'type' => 'team',
            'color' => '#0f766e',
            'timezone' => 'Europe/Oslo',
            'is_visible_by_default' => true,
        ]);
        $teamEvent = CalendarEvent::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'calendar_id' => $teamCalendar->id,
            'title' => 'Team handoff',
            'starts_at' => Carbon::parse('2026-05-18 11:00', 'Europe/Oslo')->utc(),
            'ends_at' => Carbon::parse('2026-05-18 12:00', 'Europe/Oslo')->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'confirmed',
            'transparency' => 'busy',
            'visibility' => 'public',
            'created_by' => $viewer->id,
        ]);
        $hiddenCalendar = Calendar::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Hidden operations',
            'slug' => 'hidden-operations-metadata-test',
            'type' => 'shared',
            'color' => '#6c757d',
            'timezone' => 'Europe/Oslo',
            'is_visible_by_default' => false,
        ]);
        $hiddenEvent = CalendarEvent::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'calendar_id' => $hiddenCalendar->id,
            'title' => 'Hidden event',
            'starts_at' => Carbon::parse('2026-05-18 13:00', 'Europe/Oslo')->utc(),
            'ends_at' => Carbon::parse('2026-05-18 14:00', 'Europe/Oslo')->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'confirmed',
            'transparency' => 'busy',
            'visibility' => 'public',
            'created_by' => $viewer->id,
        ]);

        $events = app(CalendarOverlayQuery::class)->eventsForRange(
            $viewer,
            Carbon::parse('2026-05-18 00:00', 'Europe/Oslo'),
            Carbon::parse('2026-05-19 00:00', 'Europe/Oslo'),
        );
        $personalMetadata = $events->firstWhere('id', $personalEvent->id);
        $teamMetadata = $events->firstWhere('id', $teamEvent->id);

        $this->assertNotNull($personalMetadata);
        $this->assertSame('user', $personalMetadata['owner_kind']);
        $this->assertSame($this->tech->id, $personalMetadata['owner_id']);
        $this->assertSame('Ada Tech', $personalMetadata['owner_label']);
        $this->assertSame('AT', $personalMetadata['owner_initials']);
        $this->assertSame('AT', $personalMetadata['owner_badge']);
        $this->assertSame('personal', $personalMetadata['calendar_type']);
        $this->assertSame('people', $personalMetadata['ownership_group']);
        $this->assertFalse($personalMetadata['is_owned_by_viewer']);
        $this->assertNotNull($teamMetadata);
        $this->assertSame('none', $teamMetadata['owner_kind']);
        $this->assertNull($teamMetadata['owner_id']);
        $this->assertSame('Dispatch team', $teamMetadata['owner_label']);
        $this->assertSame('TEAM', $teamMetadata['owner_badge']);
        $this->assertSame('team', $teamMetadata['calendar_type']);
        $this->assertSame('team', $teamMetadata['ownership_group']);
        $this->assertFalse($events->contains('id', $hiddenEvent->id));

        $ownerEvents = app(CalendarOverlayQuery::class)->eventsForRange(
            $this->tech,
            Carbon::parse('2026-05-18 00:00', 'Europe/Oslo'),
            Carbon::parse('2026-05-19 00:00', 'Europe/Oslo'),
        );
        $ownerMetadata = $ownerEvents->firstWhere('id', $personalEvent->id);
        $this->assertSame('mine', $ownerMetadata['ownership_group']);
        $this->assertTrue($ownerMetadata['is_owned_by_viewer']);
    }

    #[Test]
    public function calendar_views_render_accessible_owner_badges_without_leaking_private_details(): void
    {
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Bob Builder']);
        $viewer->assignRole('Tech');
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->tech);
        $calendar->update(['color' => '#7c3aed']);

        CalendarEvent::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'calendar_id' => $calendar->id,
            'title' => 'Visible schedule',
            'starts_at' => Carbon::parse('2026-05-18 09:00', 'Europe/Oslo')->utc(),
            'ends_at' => Carbon::parse('2026-05-18 10:00', 'Europe/Oslo')->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'confirmed',
            'transparency' => 'busy',
            'visibility' => 'public',
            'created_by' => $viewer->id,
        ]);
        CalendarEvent::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'calendar_id' => $calendar->id,
            'title' => 'Doctor appointment',
            'description' => 'Sensitive diagnosis',
            'location' => 'Private clinic',
            'starts_at' => Carbon::parse('2026-05-18 11:00', 'Europe/Oslo')->utc(),
            'ends_at' => Carbon::parse('2026-05-18 12:00', 'Europe/Oslo')->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'confirmed',
            'transparency' => 'busy',
            'visibility' => 'private',
            'created_by' => $this->tech->id,
        ]);

        foreach (['day', 'week', 'month', 'list'] as $viewMode) {
            $response = $this->actingAs($viewer)->get(route('tech.calendar.index', [
                'view' => $viewMode,
                'date' => '2026-05-18',
            ]));

            $response
                ->assertOk()
                ->assertSee('Visible schedule')
                ->assertSee('Busy')
                ->assertSee('data-calendar-owner-badge="AT"', false)
                ->assertSee('aria-label="Owner: Ada Tech. Personal calendar."', false)
                ->assertSee('calendar-owner-swatch', false)
                ->assertSee('--owner-color: #7c3aed', false)
                ->assertSee('calendar-event-title', false)
                ->assertDontSee('Doctor appointment')
                ->assertDontSee('Sensitive diagnosis')
                ->assertDontSee('Private clinic');
        }

        $this->actingAs($viewer)
            ->get(route('tech.calendar.index', [
                'view' => 'list',
                'date' => '2026-05-18',
            ]))
            ->assertOk()
            ->assertSee('calendar-calendar-identity', false)
            ->assertSee('aria-label="Calendar: Ada Tech work calendar"', false);
    }

    #[Test]
    public function calendar_views_distinguish_non_personal_calendar_types_without_leaking_private_details(): void
    {
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Private Owner']);
        $other->assignRole('Tech');

        $calendars = collect([
            'Team rotation' => $this->createVisibleCalendar('Team rotation', 'team', '#0f766e'),
            'Shared delivery' => $this->createVisibleCalendar('Shared delivery', 'shared', '#7c3aed'),
            'Company all hands' => $this->createVisibleCalendar('Company all hands', 'company', '#b45309'),
            'Meeting room' => $this->createVisibleCalendar('Meeting room', 'resource', '#be123c'),
            'External feed' => $this->createVisibleCalendar('External feed', 'external', '#0369a1'),
        ]);

        foreach ($calendars as $title => $calendar) {
            $this->createCalendarEvent($calendar, $title);
        }

        $this->createCalendarEvent(
            $calendars['Meeting room'],
            'Confidential resource booking',
            '14:00',
            'private',
            $other,
        );

        $response = $this->actingAs($this->tech)->get(route('tech.calendar.index', [
            'view' => 'list',
            'date' => '2026-05-18',
        ]));

        $response
            ->assertOk()
            ->assertSee('data-calendar-type="team"', false)
            ->assertSee('data-calendar-type="shared"', false)
            ->assertSee('data-calendar-type="company"', false)
            ->assertSee('data-calendar-type="resource"', false)
            ->assertSee('data-calendar-type="external"', false)
            ->assertSee('aria-label="Calendar type: Team"', false)
            ->assertSee('aria-label="Calendar type: Global"', false)
            ->assertSee('>TM</span>', false)
            ->assertSee('>GLB</span>', false)
            ->assertSee('>RES</span>', false)
            ->assertSee('Busy')
            ->assertDontSee('Confidential resource booking');
    }

    #[Test]
    public function ownership_filters_use_visible_calendar_ownership_and_only_mine_ignores_event_creator(): void
    {
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Other Technician']);
        $other->assignRole('Tech');
        $mine = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->tech);
        $theirs = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($other);
        $team = $this->createVisibleCalendar('Team board', 'team');
        $resource = $this->createVisibleCalendar('Workshop bay', 'resource');
        $hiddenTeam = $this->createVisibleCalendar('Hidden team', 'team');
        $hiddenTeam->update(['is_visible_by_default' => false]);

        $this->createCalendarEvent($mine, 'Owned calendar event');
        $this->createCalendarEvent($theirs, 'Other person event', '10:00');
        $this->createCalendarEvent($team, 'Created by me on team calendar', '11:00', 'public', $this->tech);
        $this->createCalendarEvent($resource, 'Resource booking', '12:00');
        $this->createCalendarEvent($hiddenTeam, 'Unauthorized hidden event', '13:00');

        $this->actingAs($this->tech)
            ->get(route('tech.calendar.index', [
                'view' => 'list',
                'date' => '2026-05-18',
                'only_mine' => 1,
            ]))
            ->assertOk()
            ->assertSee('Owned calendar event')
            ->assertDontSee('Other person event')
            ->assertDontSee('Created by me on team calendar')
            ->assertDontSee('Resource booking')
            ->assertSee('name="only_mine" value="1"', false);

        $this->actingAs($this->tech)
            ->get(route('tech.calendar.index', [
                'view' => 'list',
                'date' => '2026-05-18',
                'ownership_groups' => ['team', 'resources'],
            ]))
            ->assertOk()
            ->assertSee('Created by me on team calendar')
            ->assertSee('Resource booking')
            ->assertDontSee('Owned calendar event')
            ->assertDontSee('Other person event')
            ->assertDontSee('Unauthorized hidden event')
            ->assertSee('name="ownership_groups[]" value="team"', false)
            ->assertSee('name="ownership_groups[]" value="resources"', false);

        $this->actingAs($this->tech)
            ->get(route('tech.calendar.index', [
                'view' => 'list',
                'date' => '2026-05-18',
                'ownership_groups' => ['team'],
                'calendars' => [$resource->id],
            ]))
            ->assertOk()
            ->assertSee('No events match the selected ownership filters in this range.')
            ->assertDontSee('Created by me on team calendar')
            ->assertDontSee('Resource booking');

        $this->actingAs($this->tech)
            ->get(route('tech.calendar.index', [
                'view' => 'list',
                'date' => '2026-05-18',
                'ownership_groups' => ['team'],
                'calendars' => [$hiddenTeam->id],
            ]))
            ->assertOk()
            ->assertSee('No events match the selected ownership filters in this range.')
            ->assertDontSee('Unauthorized hidden event');
    }

    #[Test]
    public function dense_month_view_limits_events_and_preserves_filter_state_in_more_link(): void
    {
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->tech);
        $events = collect();

        foreach (['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00'] as $index => $time) {
            $events->push($this->createCalendarEvent($calendar, 'Dense event '.($index + 1), $time));
        }

        $response = $this->actingAs($this->tech)->get(route('tech.calendar.index', [
            'view' => 'month',
            'date' => '2026-05-18',
            'calendars' => [$calendar->id],
            'only_mine' => 1,
            'event_search' => 'Dense',
            'event_sort' => 'starts_at',
            'event_direction' => 'asc',
        ]));

        $response
            ->assertOk()
            ->assertSee('role="region" tabindex="0"', false)
            ->assertSee('min-width: 56rem;', false)
            ->assertSee('aria-label="2 more events on 2026-05-18"', false)
            ->assertSee('+2 more')
            ->assertSee('view=day', false)
            ->assertSee('only_mine=1', false)
            ->assertDontSee('data-event-id="'.$events[5]->id.'"', false)
            ->assertDontSee('data-event-id="'.$events[6]->id.'"', false);

        $this->assertSame(5, substr_count($response->getContent(), 'data-event-id='));
    }

    #[Test]
    public function single_event_api_masks_private_details_and_keeps_safe_ownership_metadata(): void
    {
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Bob Builder']);
        $viewer->assignRole('Tech');
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->tech);
        $event = CalendarEvent::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'calendar_id' => $calendar->id,
            'title' => 'Private customer meeting',
            'description' => 'Sensitive agenda',
            'location' => 'Private office',
            'meeting_url' => 'https://meet.example.test/private',
            'starts_at' => Carbon::parse('2026-05-18 15:00', 'Europe/Oslo')->utc(),
            'ends_at' => Carbon::parse('2026-05-18 16:00', 'Europe/Oslo')->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'confirmed',
            'transparency' => 'busy',
            'visibility' => 'private',
            'created_by' => $this->tech->id,
            'source' => 'external',
            'external_uid' => 'private-external-uid',
        ]);
        $event->participants()->create([
            'participant_type' => 'email',
            'name' => 'Secret Participant',
            'email' => 'secret@example.test',
            'role' => 'required',
            'response_status' => 'accepted',
        ]);

        Sanctum::actingAs($viewer, ['calendar.read']);

        $this->getJson(route('api.v1.calendar.events.show', $event))
            ->assertOk()
            ->assertJsonPath('data.title', 'Busy')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.location', null)
            ->assertJsonPath('data.meeting_url', null)
            ->assertJsonPath('data.details_visible', false)
            ->assertJsonPath('data.source', null)
            ->assertJsonPath('data.external_uid', null)
            ->assertJsonPath('data.created_by', null)
            ->assertJsonPath('data.owner_kind', 'user')
            ->assertJsonPath('data.owner_id', $this->tech->id)
            ->assertJsonPath('data.owner_label', 'Ada Tech')
            ->assertJsonPath('data.owner_badge', 'AT')
            ->assertJsonPath('data.calendar_type', 'personal')
            ->assertJsonPath('data.ownership_group', 'people')
            ->assertJsonPath('data.is_owned_by_viewer', false)
            ->assertJsonCount(0, 'data.participants');

        Sanctum::actingAs($this->tech, ['calendar.read']);

        $this->getJson(route('api.v1.calendar.events.show', $event))
            ->assertOk()
            ->assertJsonPath('data.title', 'Private customer meeting')
            ->assertJsonPath('data.description', 'Sensitive agenda')
            ->assertJsonPath('data.details_visible', true)
            ->assertJsonPath('data.participants.0.email', 'secret@example.test')
            ->assertJsonPath('data.ownership_group', 'mine')
            ->assertJsonPath('data.is_owned_by_viewer', true);
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

    private function createVisibleCalendar(string $name, string $type, string $color = '#2563eb'): Calendar
    {
        return Calendar::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)),
            'type' => $type,
            'color' => $color,
            'timezone' => 'Europe/Oslo',
            'is_active' => true,
            'is_visible_by_default' => true,
            'visibility_default' => 'public',
            'transparency_default' => 'busy',
        ]);
    }

    private function createCalendarEvent(
        Calendar $calendar,
        string $title,
        string $time = '09:00',
        string $visibility = 'public',
        ?User $creator = null,
    ): CalendarEvent {
        $startsAt = Carbon::parse('2026-05-18 '.$time, 'Europe/Oslo');

        return CalendarEvent::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'calendar_id' => $calendar->id,
            'title' => $title,
            'starts_at' => $startsAt->copy()->utc(),
            'ends_at' => $startsAt->copy()->addHour()->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'confirmed',
            'transparency' => 'busy',
            'visibility' => $visibility,
            'created_by' => ($creator ?? $this->tech)->id,
        ]);
    }
}
