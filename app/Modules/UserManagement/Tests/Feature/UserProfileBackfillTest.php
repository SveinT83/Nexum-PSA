<?php

namespace App\Modules\UserManagement\Tests\Feature;

use App\Models\Core\User;
use App\Modules\UserManagement\Actions\BackfillUserProfiles;
use App\Modules\UserManagement\Models\UserProfile;
use App\Modules\Ticket\Models\TicketTechnicianProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserProfileBackfillTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function backfill_creates_user_profiles_from_existing_users_and_ticket_profiles(): void
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'phone_work' => '+47 73502020',
            'phone_private' => '+47 40002020',
        ]);

        $ticketProfile = TicketTechnicianProfile::create([
            'user_id' => $user->id,
            'is_assignable' => true,
            'max_open_tickets' => 8,
            'timezone' => 'Europe/Oslo',
            'working_hours' => [
                'monday' => ['enabled' => true, 'start' => '10:00', 'end' => '18:00'],
            ],
            'notes' => 'Migrated ticket note.',
        ]);

        $summary = app(BackfillUserProfiles::class)->handle();

        $this->assertSame(1, $summary['profiles_created']);
        $this->assertSame(1, $summary['ticket_profiles_used']);

        $profile = UserProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('+47 73502020', $profile->work_phone);
        $this->assertSame('+47 40002020', $profile->private_phone);
        $this->assertSame('Europe/Oslo', $profile->timezone);
        $this->assertSame('10:00', $profile->working_hours['monday']['start']);
        $this->assertSame('Migrated ticket note.', $profile->profile_notes);
        $this->assertSame($ticketProfile->id, $profile->migrated_from_ticket_technician_profile_id);
        $this->assertNotNull($profile->migrated_at);
    }

    #[Test]
    public function backfill_command_is_idempotent_and_safe_for_production_upgrades(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        app(BackfillUserProfiles::class)->handle();
        app(BackfillUserProfiles::class)->handle();

        $this->assertSame(1, UserProfile::query()->where('user_id', $user->id)->count());
    }
}
