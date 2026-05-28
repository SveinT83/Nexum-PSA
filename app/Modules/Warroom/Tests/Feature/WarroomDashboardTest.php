<?php

namespace App\Modules\Warroom\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WarroomDashboardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dashboard_shows_next_calendar_event_when_today_has_no_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-28 09:00', 'Europe/Oslo'));

        Role::create(['name' => 'Tech']);

        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tech->assignRole('Tech');

        $calendar = Calendar::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Team calendar',
            'slug' => 'team-calendar',
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
            'title' => 'Tomorrow dispatch',
            'starts_at' => Carbon::parse('2026-05-29 10:00', 'Europe/Oslo')->utc(),
            'ends_at' => Carbon::parse('2026-05-29 11:00', 'Europe/Oslo')->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'confirmed',
            'transparency' => 'busy',
            'visibility' => 'public',
            'created_by' => $tech->id,
        ]);

        $this->actingAs($tech)
            ->get(route('tech.dashboard'))
            ->assertOk()
            ->assertSee('Next event')
            ->assertSee('Tomorrow dispatch')
            ->assertDontSee('No upcoming calendar events.');

        Carbon::setTestNow();
    }
}
