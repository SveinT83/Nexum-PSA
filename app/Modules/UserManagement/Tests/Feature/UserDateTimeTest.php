<?php

namespace App\Modules\UserManagement\Tests\Feature;

use App\Models\Core\User;
use App\Modules\UserManagement\Models\UserPreference;
use App\Modules\UserManagement\Models\UserProfile;
use App\Modules\UserManagement\Support\UserDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Display conversion must preserve persisted instants and the existing profile fallback order. */
class UserDateTimeTest extends TestCase
{
    private function viewer(?string $profileZone = 'Europe/Oslo', ?string $legacyZone = 'UTC'): User
    {
        $user = new User;
        $user->setRelation('profile', $profileZone === null ? null : new UserProfile(['timezone' => $profileZone]));
        $user->setRelation('preferences', $legacyZone === null ? null : new UserPreference(['timezone' => $legacyZone]));

        return $user;
    }

    public function test_profile_legacy_application_and_guest_fallbacks_match_workday_without_writes(): void
    {
        config(['app.timezone' => 'UTC']);
        $formatter = app(UserDateTime::class);
        $this->assertSame('Europe/Oslo', $formatter->timezone($this->viewer()));
        $this->assertSame('America/New_York', $formatter->timezone($this->viewer(null, 'America/New_York')));
        $this->assertSame('UTC', $formatter->timezone($this->viewer(null, null)));
        $this->assertSame('UTC', $formatter->timezone());
        $this->assertNull($formatter->format(null, user: $this->viewer()));
    }

    public function test_winter_summer_and_local_midnight_convert_once_without_mutating_carbon(): void
    {
        $formatter = app(UserDateTime::class);
        $user = $this->viewer();
        foreach ([
            ['2026-10-29T19:58:00Z', '2026-10-29 20:58'],
            ['2026-07-29T19:58:00Z', '2026-07-29 21:58'],
            ['2026-10-29T23:30:00Z', '2026-10-30 00:30'],
            ['2026-07-29T22:30:00Z', '2026-07-30 00:30'],
        ] as [$input, $expected]) {
            $instant = Carbon::parse($input);
            $before = $instant->toIso8601String();
            $this->assertSame($expected, $formatter->format($instant, user: $user));
            $this->assertSame($before, $instant->toIso8601String());
            $this->assertSame($expected, $formatter->format($instant->copy()->setTimezone('Europe/Oslo'), user: $user));
        }
    }

    public function test_dst_gap_and_repeated_hour_preserve_actual_instants_and_can_show_offsets(): void
    {
        $formatter = app(UserDateTime::class);
        $user = $this->viewer();
        foreach ([
            ['2026-03-29T00:30:00Z', '2026-03-29 01:30 +01:00'],
            ['2026-03-29T01:30:00Z', '2026-03-29 03:30 +02:00'],
            ['2026-10-25T00:30:00Z', '2026-10-25 02:30 +02:00'],
            ['2026-10-25T01:30:00Z', '2026-10-25 02:30 +01:00'],
        ] as [$input, $expected]) {
            $this->assertSame($expected, $formatter->format(CarbonImmutable::parse($input), 'Y-m-d H:i P', $user));
        }
    }
}
