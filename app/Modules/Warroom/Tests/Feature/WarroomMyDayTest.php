<?php

namespace App\Modules\Warroom\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStatus;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WarroomMyDayTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);

        $this->tech = User::factory()->create([
            'email' => 'tech@example.test',
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->tech->assignRole('Tech');
        $this->tech->givePermissionTo('warroom.view');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function my_day_shows_warroom_side_menu(): void
    {
        $this->actingAs($this->tech)
            ->get(route('tech.my-day.index'))
            ->assertOk()
            ->assertSee('Warroom') // Side menu title
            ->assertSee('Dashboard')
            ->assertSee('My Day')
            ->assertSee(route('tech.dashboard'))
            ->assertSee(route('tech.my-day.index'));
    }

    #[Test]
    public function my_day_navigation_keeps_dashboard_group_active_and_marks_only_my_day_current(): void
    {
        $response = $this->actingAs($this->tech)
            ->get(route('tech.my-day.index'))
            ->assertOk();

        $html = $response->getContent();
        $dashboardUrl = preg_quote(route('tech.dashboard'), '/');
        $myDayUrl = preg_quote(route('tech.my-day.index'), '/');

        $this->assertSame(2, preg_match_all(
            '/<a class="nav-link dropdown-toggle active"[^>]*>Dashboard<\\/a>/',
            $html
        ));
        $this->assertSame(2, preg_match_all(
            '/<a class="dropdown-item active"\\s+aria-current="page"\\s+href="'.$myDayUrl.'">My Day<\\/a>/',
            $html
        ));
        $this->assertSame(2, preg_match_all(
            '/<a class="dropdown-item"\\s+\\s*href="'.$dashboardUrl.'">Dashboard<\\/a>/',
            $html
        ));
        $this->assertDoesNotMatchRegularExpression(
            '/<a[^>]*aria-current="page"[^>]*href="'.$dashboardUrl.'"/',
            $html
        );
    }

    #[Test]
    public function my_day_requires_warroom_view_permission(): void
    {
        $unauthorizedTech = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);
        $unauthorizedTech->assignRole('Tech');

        $this->actingAs($unauthorizedTech)
            ->get(route('tech.my-day.index'))
            ->assertForbidden();
    }

    #[Test]
    public function my_day_drill_down_links_remain_accessible_when_counts_are_zero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 09:15', 'Europe/Oslo'));

        $response = $this->actingAs($this->tech)
            ->get(route('tech.my-day.index'))
            ->assertOk()
            ->assertSee(route('tech.tickets.index', ['ownership' => 'mine', 'lifecycle' => 'open']))
            ->assertSee(route('tech.tasks.index', ['mine' => 1]))
            ->assertSee(route('tech.calendar.index', ['view' => 'day', 'date' => '2026-07-05']))
            ->assertSee(route('tech.tickets.index', ['ownership' => 'mine', 'lifecycle' => 'open', 'unread' => 1]))
            ->assertSee('aria-label="Open tickets assigned to you: 0"', false)
            ->assertSee('aria-label="Open tasks assigned to you: 0"', false)
            ->assertSee('aria-label="Calendar events for 2026-07-05: 0"', false)
            ->assertSee('aria-label="Overdue tickets and tasks: 0"', false)
            ->assertSee('aria-label="Unread open tickets assigned to you: 0"', false);

        $this->assertSame(
            2,
            substr_count($response->getContent(), route('tech.my-day.index', ['focus' => 'overdue']))
        );
    }

    #[Test]
    public function my_day_shows_the_signed_in_technicians_personal_work(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 09:15', 'Europe/Oslo'));

        $otherTech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $openStatus = TaskStatus::query()->create([
            'name' => 'Open',
            'slug' => 'open',
            'is_default' => true,
            'is_active' => true,
            'is_open' => true,
            'sort_order' => 1,
        ]);

        $doneStatus = TaskStatus::query()->create([
            'name' => 'Done',
            'slug' => 'done',
            'is_active' => true,
            'is_done' => true,
            'sort_order' => 2,
        ]);

        $cancelledStatus = TaskStatus::query()->create([
            'name' => 'Cancelled',
            'slug' => 'cancelled',
            'is_active' => true,
            'is_cancelled' => true,
            'sort_order' => 3,
        ]);

        $assignedTicket = Ticket::factory()->create([
            'owner_id' => $this->tech->id,
            'subject' => 'Assigned router outage',
            'is_unread' => true,
            'resolve_due_at' => now()->addHour(),
        ]);

        Ticket::factory()->create([
            'owner_id' => $otherTech->id,
            'subject' => 'Other technician ticket',
            'resolve_due_at' => now()->addHour(),
        ]);

        Ticket::factory()->create([
            'owner_id' => $this->tech->id,
            'subject' => 'Closed personal ticket',
            'closed_at' => now()->subMinute(),
        ]);

        $assignedTask = Task::query()->create([
            'title' => 'Prepare onsite kit',
            'owner_type' => User::class,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'assigned_to' => $this->tech->id,
            'status_id' => $openStatus->id,
            'due_at' => now()->addHours(2),
        ]);

        Task::query()->create([
            'title' => 'Completed personal task',
            'owner_type' => User::class,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'assigned_to' => $this->tech->id,
            'status_id' => $openStatus->id,
            'completed_at' => now()->subMinute(),
        ]);

        Task::query()->create([
            'title' => 'Done personal task',
            'owner_type' => User::class,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'assigned_to' => $this->tech->id,
            'status_id' => $doneStatus->id,
            'due_at' => now()->subHour(),
        ]);

        Task::query()->create([
            'title' => 'Cancelled personal task',
            'owner_type' => User::class,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'assigned_to' => $this->tech->id,
            'status_id' => $cancelledStatus->id,
            'due_at' => now()->subHour(),
        ]);

        Task::query()->create([
            'title' => 'Other technician task',
            'owner_type' => User::class,
            'owner_id' => $otherTech->id,
            'created_by' => $otherTech->id,
            'assigned_to' => $otherTech->id,
            'status_id' => $openStatus->id,
            'due_at' => now()->addHours(2),
        ]);

        $calendar = Calendar::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Field calendar',
            'slug' => 'field-calendar',
            'type' => 'shared',
            'timezone' => 'Europe/Oslo',
            'is_active' => true,
            'is_default' => false,
            'is_visible_by_default' => true,
            'visibility_default' => 'default',
            'transparency_default' => 'busy',
        ]);

        CalendarEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'calendar_id' => $calendar->id,
            'title' => 'Customer visit',
            'starts_at' => now()->addHour()->utc(),
            'ends_at' => now()->addHours(2)->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'confirmed',
            'transparency' => 'busy',
            'visibility' => 'public',
            'created_by' => $this->tech->id,
        ]);

        CalendarEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'calendar_id' => $calendar->id,
            'title' => 'Other technician event',
            'starts_at' => now()->addHour()->utc(),
            'ends_at' => now()->addHours(2)->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'confirmed',
            'transparency' => 'busy',
            'visibility' => 'public',
            'created_by' => $otherTech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.my-day.index'))
            ->assertOk()
            ->assertViewIs('warroom::Tech.my-day')
            ->assertSee('Assigned router outage')
            ->assertSee('Prepare onsite kit')
            ->assertSee('Customer visit')
            ->assertSee('manifest.json')
            ->assertSee('serviceWorker')
            ->assertDontSee('Other technician ticket')
            ->assertDontSee('Other technician task')
            ->assertDontSee('Other technician event')
            ->assertDontSee('Closed personal ticket')
            ->assertDontSee('Completed personal task')
            ->assertDontSee('Done personal task')
            ->assertDontSee('Cancelled personal task')
            ->assertSee(route('tech.tickets.show', $assignedTicket))
            ->assertSee(route('tech.tasks.show', $assignedTask))
            ->assertSee(route('tech.tickets.index', ['ownership' => 'mine', 'lifecycle' => 'open']))
            ->assertSee(route('tech.tasks.index', ['mine' => 1]))
            ->assertSee(route('tech.calendar.index', ['view' => 'day', 'date' => '2026-07-05']))
            ->assertSee(route('tech.my-day.index', ['focus' => 'overdue']))
            ->assertSee(route('tech.tickets.index', ['ownership' => 'mine', 'lifecycle' => 'open', 'unread' => 1]));
    }

    #[Test]
    public function my_day_counts_full_scope_while_preview_rows_stay_limited(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 09:15', 'Europe/Oslo'));

        $openStatus = TaskStatus::query()->create([
            'name' => 'Open',
            'slug' => 'open-counts',
            'is_default' => true,
            'is_active' => true,
            'is_open' => true,
            'sort_order' => 1,
        ]);

        $calendar = Calendar::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Counts calendar',
            'slug' => 'counts-calendar',
            'type' => 'shared',
            'timezone' => 'Europe/Oslo',
            'is_active' => true,
            'is_default' => false,
            'is_visible_by_default' => true,
            'visibility_default' => 'default',
            'transparency_default' => 'busy',
        ]);

        foreach (range(1, 10) as $index) {
            Ticket::factory()->create([
                'owner_id' => $this->tech->id,
                'subject' => sprintf('Assigned ticket %02d', $index),
                'resolve_due_at' => now()->addHour(),
            ]);

            Task::query()->create([
                'title' => sprintf('Assigned task %02d', $index),
                'owner_type' => User::class,
                'owner_id' => $this->tech->id,
                'created_by' => $this->tech->id,
                'assigned_to' => $this->tech->id,
                'status_id' => $openStatus->id,
                'due_at' => now()->addHours(2),
            ]);
        }

        foreach (range(1, 14) as $index) {
            CalendarEvent::query()->create([
                'uuid' => (string) Str::uuid(),
                'calendar_id' => $calendar->id,
                'title' => sprintf('Calendar event %02d', $index),
                'starts_at' => now()->addHour()->utc(),
                'ends_at' => now()->addHours(2)->utc(),
                'timezone' => 'Europe/Oslo',
                'status' => 'confirmed',
                'transparency' => 'busy',
                'visibility' => 'public',
                'created_by' => $this->tech->id,
            ]);
        }

        $this->actingAs($this->tech)
            ->get(route('tech.my-day.index'))
            ->assertOk()
            ->assertViewHas('myDay', fn (array $myDay): bool => $myDay['counts']['tickets'] === 10
                && $myDay['counts']['tasks'] === 10
                && $myDay['counts']['events'] === 14
                && $myDay['tickets']->count() === 8
                && $myDay['tasks']->count() === 8
                && $myDay['events']->count() === 12)
            ->assertSee('aria-label="Open tickets assigned to you: 10"', false)
            ->assertSee('aria-label="Open tasks assigned to you: 10"', false)
            ->assertSee('aria-label="Calendar events for 2026-07-05: 14"', false);
    }

    #[Test]
    public function overdue_focus_shows_combined_tickets_and_tasks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 09:15', 'Europe/Oslo'));
        $now = now();

        $otherTech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $openStatus = TaskStatus::query()->create([
            'name' => 'Open',
            'slug' => 'open',
            'is_default' => true,
            'is_active' => true,
            'is_open' => true,
            'sort_order' => 1,
        ]);

        $overdueTicket = Ticket::factory()->create([
            'owner_id' => $this->tech->id,
            'subject' => 'URGENT Overdue Ticket',
            'resolve_due_at' => $now->copy()->subDay(),
        ]);

        $overdueTask = Task::query()->create([
            'title' => 'URGENT Overdue Task',
            'owner_type' => User::class,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'assigned_to' => $this->tech->id,
            'status_id' => $openStatus->id,
            'due_at' => $now->copy()->subDay(),
        ]);

        Ticket::factory()->create([
            'owner_id' => $otherTech->id,
            'subject' => 'Other technician overdue ticket',
            'resolve_due_at' => $now->copy()->subDay(),
        ]);

        Ticket::factory()->create([
            'owner_id' => $this->tech->id,
            'subject' => 'Future personal ticket',
            'resolve_due_at' => $now->copy()->addDay(),
        ]);

        Task::query()->create([
            'title' => 'Other technician overdue task',
            'owner_type' => User::class,
            'owner_id' => $otherTech->id,
            'created_by' => $otherTech->id,
            'assigned_to' => $otherTech->id,
            'status_id' => $openStatus->id,
            'due_at' => $now->copy()->subDay(),
        ]);

        Task::query()->create([
            'title' => 'Future personal task',
            'owner_type' => User::class,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'assigned_to' => $this->tech->id,
            'status_id' => $openStatus->id,
            'due_at' => $now->copy()->addDay(),
        ]);

        $response = $this->actingAs($this->tech)
            ->get(route('tech.my-day.index', ['focus' => 'overdue']))
            ->assertOk()
            ->assertSee('Overdue Work Items')
            ->assertSee('URGENT Overdue Ticket')
            ->assertSee('URGENT Overdue Task')
            ->assertSee(route('tech.tickets.show', $overdueTicket))
            ->assertSee(route('tech.tasks.show', $overdueTask))
            ->assertDontSee('Other technician overdue ticket')
            ->assertDontSee('Other technician overdue task');

        $overdueItems = $response->viewData('myDay')['overdue_items'];

        $this->assertCount(2, $overdueItems);
        $this->assertEqualsCanonicalizing(
            ['URGENT Overdue Ticket', 'URGENT Overdue Task'],
            $overdueItems->pluck('title')->all()
        );
    }
}
