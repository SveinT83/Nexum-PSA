<?php

namespace App\Modules\UserManagement\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\UserManagement\Controllers\ProfilePreferencesController;
use App\Modules\UserManagement\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserPreferencesTest extends TestCase
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
    public function profile_preference_routes_are_owned_by_user_management(): void
    {
        $this->assertSame(ProfilePreferencesController::class.'@show', Route::getRoutes()->getByName('tech.profile.preferences')->getActionName());
        $this->assertSame(ProfilePreferencesController::class.'@update', Route::getRoutes()->getByName('tech.profile.preferences.update')->getActionName());
    }

    #[Test]
    public function technician_can_view_preferences_page(): void
    {
        $this->actingAs($this->tech)
            ->get(route('tech.profile.preferences'))
            ->assertOk()
            ->assertSee('User Preferences')
            ->assertSee('Default calendar view');

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $this->tech->id,
            'default_calendar_view' => 'week',
        ]);
    }

    #[Test]
    public function technician_can_update_preferences_and_calendar_availability_defaults(): void
    {
        app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->tech);

        $this->actingAs($this->tech)
            ->patch(route('tech.profile.preferences.update'), [
                'timezone' => 'America/New_York',
                'default_calendar_view' => 'month',
                'workday_start' => '09:00',
                'workday_end' => '17:00',
            ])
            ->assertRedirect(route('tech.profile.preferences'));

        $preferences = UserPreference::query()->where('user_id', $this->tech->id)->firstOrFail();
        $this->assertSame('America/New_York', $preferences->timezone);
        $this->assertSame('month', $preferences->default_calendar_view);

        $calendar = Calendar::query()
            ->where('owner_type', User::class)
            ->where('owner_id', $this->tech->id)
            ->firstOrFail();

        $this->assertSame('America/New_York', $calendar->timezone);
        $this->assertDatabaseHas('calendar_availability_rules', [
            'calendar_id' => $calendar->id,
            'weekday' => 1,
            'starts_at_local' => '09:00',
            'ends_at_local' => '17:00',
        ]);
    }
}
