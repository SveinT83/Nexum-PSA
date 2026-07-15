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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

        Ticket::factory()->create([
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

        Task::query()->create([
            'title' => 'Prepare onsite kit',
            'owner_type' => User::class,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'assigned_to' => $this->tech->id,
            'status_id' => $openStatus->id,
            'due_at' => now()->addHours(2),
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
            ->assertDontSee('Other technician event');
    }
}
