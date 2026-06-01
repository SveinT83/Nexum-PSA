<?php

namespace App\Modules\Warroom\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Warroom\Support\WarroomSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WarroomDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);

        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
    }

    #[Test]
    public function dashboard_shows_next_calendar_event_when_today_has_no_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-28 09:00', 'Europe/Oslo'));

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
            'created_by' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.dashboard'))
            ->assertOk()
            ->assertSee('Next event')
            ->assertSee('Tomorrow dispatch')
            ->assertDontSee('No upcoming calendar events.');

        Carbon::setTestNow();
    }

    #[Test]
    public function admin_can_update_warroom_settings(): void
    {
        $this->actingAs($this->tech)
            ->get(route('tech.admin.settings.warroom'))
            ->assertOk()
            ->assertViewIs('warroom::Admin.Settings.edit')
            ->assertSee('Warroom Settings')
            ->assertSee('Operational Windows');

        $this->actingAs($this->tech)
            ->put(route('tech.admin.settings.warroom.update'), [
                'due_soon_hours' => 12,
                'inbox_recent_hours' => 48,
                'latest_tickets_limit' => 4,
                'latest_alerts_limit' => 3,
                'calendar_today_limit' => 2,
                'recent_integrations_limit' => 2,
                'enabled_sections' => ['pulse', 'tickets', 'system_health'],
            ])
            ->assertRedirect(route('tech.admin.settings.warroom'));

        $settings = json_decode(CommonSetting::query()->where('type', 'warroom')->where('name', 'dashboard')->value('json'), true);

        $this->assertSame(12, $settings['due_soon_hours']);
        $this->assertSame(48, $settings['inbox_recent_hours']);
        $this->assertSame(4, $settings['latest_tickets_limit']);
        $this->assertSame(['pulse', 'tickets', 'system_health'], $settings['enabled_sections']);
    }

    #[Test]
    public function dashboard_respects_visible_panel_settings(): void
    {
        app(WarroomSettings::class)->update([
            'due_soon_hours' => 8,
            'inbox_recent_hours' => 24,
            'latest_tickets_limit' => 6,
            'latest_alerts_limit' => 5,
            'calendar_today_limit' => 5,
            'recent_integrations_limit' => 5,
            'enabled_sections' => ['pulse'],
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.dashboard'))
            ->assertOk()
            ->assertSee('Open tickets')
            ->assertDontSee('Ticket Fireline')
            ->assertDontSee('Domain Radar')
            ->assertDontSee('System Health')
            ->assertDontSee('Next Actions');
    }
}
