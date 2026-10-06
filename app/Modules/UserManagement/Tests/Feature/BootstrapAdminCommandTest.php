<?php

namespace App\Modules\UserManagement\Tests\Feature;

use App\Models\Core\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BootstrapAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function documented_fresh_install_sequence_completes_without_a_default_credential(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $password = 'Fresh+Install2026!Passphrase';

        $this->artisan('nexum:bootstrap-admin')
            ->expectsQuestion('Administrator name', 'Fresh Install Administrator')
            ->expectsQuestion('Administrator email', 'fresh-install@example.test')
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $password)
            ->doesntExpectOutputToContain($password)
            ->assertSuccessful();

        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'fresh-install@example.test')->firstOrFail();

        $this->assertTrue($user->hasRole('Superuser'));
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertNotNull($user->profile()->first());
        $this->assertSame(1, $this->humanUserCount());
    }

    #[Test]
    public function compatibility_seeder_creates_nothing_and_gives_a_safe_instruction(): void
    {
        $this->artisan('db:seed', [
            '--class' => AdminUserSeeder::class,
            '--force' => true,
        ])
            ->expectsOutputToContain('nexum:bootstrap-admin')
            ->doesntExpectOutputToContain('password')
            ->doesntExpectOutputToContain('token')
            ->assertSuccessful();

        $this->assertSame(0, $this->humanUserCount());
    }

    #[Test]
    public function compatibility_seeder_preserves_an_existing_human_superuser(): void
    {
        $superuser = Role::findOrCreate('Superuser', 'web');
        $existingPassword = Hash::make('Existing+External2026!Credential');
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'is_system_actor' => false,
            'password' => $existingPassword,
        ]);
        $user->assignRole($superuser);
        $originalRoleIds = $user->roles()->pluck('roles.id')->all();

        $this->seed(AdminUserSeeder::class);

        $user->refresh();
        $this->assertSame($existingPassword, $user->password);
        $this->assertSame($originalRoleIds, $user->roles()->pluck('roles.id')->all());
        $this->assertSame(1, $this->humanUserCount());
    }

    #[Test]
    public function interactive_command_creates_one_active_verified_human_superuser_with_profile(): void
    {
        Role::findOrCreate('Superuser', 'web');
        $password = 'Strong+Bootstrap2026!Passphrase';

        $this->artisan('nexum:bootstrap-admin')
            ->expectsQuestion('Administrator name', 'Bootstrap Administrator')
            ->expectsQuestion('Administrator email', 'BOOTSTRAP@example.test')
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $password)
            ->expectsOutput('Initial human Superuser created. No credentials were printed.')
            ->doesntExpectOutputToContain($password)
            ->assertSuccessful();

        $user = User::query()->where('email', 'bootstrap@example.test')->firstOrFail();
        $profile = $user->profile()->firstOrFail();

        $this->assertSame('Bootstrap Administrator', $user->name);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertFalse($user->isSystemActor());
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertTrue($user->hasRole('Superuser'));
        $this->assertSame(config('app.timezone', 'UTC'), $profile->timezone);
        $this->assertSame('08:00', $profile->working_hours['monday']['start']);
        $this->assertSame(1, $this->humanUserCount());
    }

    #[Test]
    public function interactive_command_rejects_a_weak_password_without_creating_a_user(): void
    {
        Role::findOrCreate('Superuser', 'web');
        $password = 'Weak1!';

        $this->artisan('nexum:bootstrap-admin')
            ->expectsQuestion('Administrator name', 'Bootstrap Administrator')
            ->expectsQuestion('Administrator email', 'bootstrap@example.test')
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $password)
            ->doesntExpectOutputToContain($password)
            ->assertFailed();

        $this->assertSame(0, $this->humanUserCount());
    }

    #[Test]
    public function interactive_command_rejects_password_confirmation_mismatch(): void
    {
        Role::findOrCreate('Superuser', 'web');
        $password = 'Strong+Bootstrap2026!Passphrase';
        $confirmation = 'Different+Bootstrap2026!Passphrase';

        $this->artisan('nexum:bootstrap-admin')
            ->expectsQuestion('Administrator name', 'Bootstrap Administrator')
            ->expectsQuestion('Administrator email', 'bootstrap@example.test')
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $confirmation)
            ->doesntExpectOutputToContain($password)
            ->doesntExpectOutputToContain($confirmation)
            ->assertFailed();

        $this->assertSame(0, $this->humanUserCount());
    }

    #[Test]
    public function command_refuses_non_interactive_execution(): void
    {
        Role::findOrCreate('Superuser', 'web');

        $this->artisan('nexum:bootstrap-admin', ['--no-interaction' => true])
            ->expectsOutputToContain('interactive local console')
            ->doesntExpectOutputToContain('password')
            ->doesntExpectOutputToContain('token')
            ->assertFailed();

        $this->assertSame(0, $this->humanUserCount());
    }

    #[Test]
    public function interactive_command_refuses_when_a_human_superuser_already_exists(): void
    {
        $superuser = Role::findOrCreate('Superuser', 'web');
        $existing = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'is_system_actor' => false,
        ]);
        $existing->assignRole($superuser);

        $this->artisan('nexum:bootstrap-admin')
            ->expectsOutputToContain('already exists')
            ->assertFailed();

        $this->assertSame(1, $this->humanUserCount());
    }

    #[Test]
    public function failed_role_assignment_rolls_back_the_user_and_profile(): void
    {
        Role::findOrCreate('Superuser', 'web');
        $profileCountBefore = DB::table('user_profiles')->count();
        DB::statement(<<<'SQL'
CREATE TRIGGER test_bootstrap_role_failure
BEFORE INSERT ON model_has_roles
BEGIN
    SELECT RAISE(ABORT, 'synthetic bootstrap role failure');
END
SQL);

        $password = 'Strong+Bootstrap2026!Passphrase';

        $this->artisan('nexum:bootstrap-admin')
            ->expectsQuestion('Administrator name', 'Rollback Administrator')
            ->expectsQuestion('Administrator email', 'rollback@example.test')
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $password)
            ->expectsOutputToContain('failed safely')
            ->doesntExpectOutputToContain($password)
            ->doesntExpectOutputToContain('synthetic bootstrap role failure')
            ->assertFailed();

        $this->assertDatabaseMissing('user_management', ['email' => 'rollback@example.test']);
        $this->assertSame($profileCountBefore, DB::table('user_profiles')->count());
        $this->assertSame(0, $this->humanUserCount());
    }

    private function humanUserCount(): int
    {
        return User::query()->where('is_system_actor', false)->count();
    }
}
