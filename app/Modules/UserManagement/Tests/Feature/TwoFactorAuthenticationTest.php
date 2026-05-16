<?php

namespace App\Modules\UserManagement\Tests\Feature;

use App\Models\Core\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function user_can_view_security_settings_page()
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $response = $this->actingAs($user)
            ->get(route('tech.profile.security'));

        $response->assertOk();
        $response->assertSee('Two-Factor Authentication');
    }

    /** @test */
    public function user_can_enable_two_factor_authentication()
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'two_factor_secret' => null,
        ]);

        $response = $this->actingAs($user)
            ->post(route('tech.profile.security.2fa.enable'));

        $response->assertRedirect(route('tech.profile.security'));
        $response->assertSessionHas('status', 'two-factor-enabled');

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at); // Not confirmed yet
    }

    /** @test */
    public function user_can_disable_two_factor_authentication()
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);

        // Enable 2FA first
        app(EnableTwoFactorAuthentication::class)->handle($user);
        $user->refresh();

        $response = $this->actingAs($user)
            ->post(route('tech.profile.security.2fa.disable'));

        $response->assertRedirect(route('tech.profile.security'));
        $response->assertSessionHas('status', 'two-factor-disabled');

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
    }

    /** @test */
    public function user_can_regenerate_recovery_codes()
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);

        app(EnableTwoFactorAuthentication::class)->handle($user);
        $user->refresh();
        $oldCodes = $user->two_factor_recovery_codes;

        $response = $this->actingAs($user)
            ->post(route('tech.profile.security.recovery-codes'));

        $response->assertRedirect(route('tech.profile.security'));
        $response->assertSessionHas('status', 'recovery-codes-regenerated');

        $user->refresh();
        $this->assertNotEquals($oldCodes, $user->two_factor_recovery_codes);
    }

    /** @test */
    public function user_can_update_password()
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'password' => bcrypt('old-password'),
        ]);

        $response = $this->actingAs($user)
            ->post(route('tech.profile.security.password'), [
                'current_password' => 'old-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ]);

        $response->assertRedirect(route('tech.profile.security'));

        $this->assertTrue(\Hash::check('new-secure-password', $user->fresh()->password));
    }

    /** @test */
    public function password_update_fails_with_wrong_current_password()
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'password' => bcrypt('correct-password'),
        ]);

        $response = $this->actingAs($user)
            ->post(route('tech.profile.security.password'), [
                'current_password' => 'wrong-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ]);

        $response->assertSessionHasErrors('current_password');
        $this->assertTrue(\Hash::check('correct-password', $user->fresh()->password));
    }

    /** @test */
    public function unauthenticated_user_cannot_access_security_settings()
    {
        $response = $this->get(route('tech.profile.security'));
        $response->assertRedirect(route('login'));
    }

    /** @test */
    public function admin_can_view_2fa_enforcement_settings()
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('superadmin');
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'superadmin']);

        $response = $this->actingAs($admin)
            ->get(route('tech.admin.user_management.2fa-settings'));

        $response->assertOk();
        $response->assertSee('2FA Enforcement');
    }

    /** @test */
    public function admin_can_enable_2fa_enforcement_for_roles()
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('superadmin');
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'superadmin']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'technician']);

        // Ensure common_settings table exists
        \DB::table('common_settings')->insertOrIgnore([
            'key' => 'enforce_two_factor',
            'value' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('common_settings')->insertOrIgnore([
            'key' => 'enforce_two_factor_roles',
            'value' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->post(route('tech.admin.user_management.2fa-settings.update'), [
                'enforce_two_factor' => '1',
                'enforce_two_factor_roles' => ['superadmin', 'technician'],
            ]);

        $response->assertRedirect(route('tech.admin.user_management.2fa-settings'));

        $this->assertEquals('1', \DB::table('common_settings')->where('key', 'enforce_two_factor')->value('value'));
        $roles = json_decode(\DB::table('common_settings')->where('key', 'enforce_two_factor_roles')->value('value'), true);
        $this->assertContains('superadmin', $roles);
        $this->assertContains('technician', $roles);
    }
}