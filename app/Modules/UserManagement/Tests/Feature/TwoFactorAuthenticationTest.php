<?php

namespace App\Modules\UserManagement\Tests\Feature;

use App\Models\Core\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Tech']);
        Role::firstOrCreate(['name' => 'Admin']);
    }

    #[Test]
    public function user_can_view_security_settings_page()
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('Tech');

        $response = $this->actingAs($user)
            ->get(route('tech.profile.security'));

        $response->assertOk();
        $response->assertSee('Two-Factor Authentication');
    }

    #[Test]
    public function user_can_enable_two_factor_authentication()
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'two_factor_secret' => null,
        ]);
        $user->assignRole('Tech');

        $response = $this->actingAs($user)
            ->post(route('tech.profile.security.2fa.enable'));

        $response->assertRedirect(route('tech.profile.security'));
        $response->assertSessionHas('status', 'two-factor-enabled');

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at); // Not confirmed yet
    }

    #[Test]
    public function pending_two_factor_setup_shows_manual_setup_key()
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->assignRole('Tech');

        app(EnableTwoFactorAuthentication::class)($user);
        $user->refresh();

        $setupKey = Crypt::decryptString($user->two_factor_secret);

        $this->actingAs($user)
            ->get(route('tech.profile.security'))
            ->assertOk()
            ->assertSee('Show TOTP code')
            ->assertSee($setupKey);
    }

    #[Test]
    public function invalid_two_factor_login_code_returns_visible_error()
    {
        Log::spy();

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('Tech');

        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $response = $this
            ->withSession(['login.id' => $user->id, 'login.remember' => false])
            ->post(route('two-factor.login.store'), [
                'code' => '000000',
            ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHasErrors('code');

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('The provided TOTP code was invalid.');

        Log::shouldHaveReceived('warning')
            ->with('Two-factor login failed because the submitted code was invalid.', \Mockery::type('array'));
    }

    #[Test]
    public function expired_two_factor_login_session_redirects_to_login_with_error()
    {
        Log::spy();

        $response = $this->post(route('two-factor.login.store'), [
            'code' => '000000',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Your two-factor login session expired.');

        Log::shouldHaveReceived('warning')
            ->with('Two-factor login failed because the challenge session was missing.', \Mockery::type('array'));
    }

    #[Test]
    public function user_can_disable_two_factor_authentication()
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->assignRole('Tech');

        // Enable 2FA first
        app(EnableTwoFactorAuthentication::class)($user);
        $user->refresh();

        $response = $this->actingAs($user)
            ->post(route('tech.profile.security.2fa.disable'));

        $response->assertRedirect(route('tech.profile.security'));
        $response->assertSessionHas('status', 'two-factor-disabled');

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
    }

    #[Test]
    public function user_can_regenerate_recovery_codes()
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->assignRole('Tech');

        app(EnableTwoFactorAuthentication::class)($user);
        $user->refresh();
        $oldCodes = $user->two_factor_recovery_codes;

        $response = $this->actingAs($user)
            ->post(route('tech.profile.security.recovery-codes'));

        $response->assertRedirect(route('tech.profile.security'));
        $response->assertSessionHas('status', 'recovery-codes-regenerated');

        $user->refresh();
        $this->assertNotEquals($oldCodes, $user->two_factor_recovery_codes);
    }

    #[Test]
    public function user_can_update_password()
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'password' => bcrypt('old-password'),
        ]);
        $user->assignRole('Tech');

        $response = $this->actingAs($user)
            ->post(route('tech.profile.security.password'), [
                'current_password' => 'old-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ]);

        $response->assertRedirect(route('tech.profile.security'));

        $this->assertTrue(\Hash::check('new-secure-password', $user->fresh()->password));
    }

    #[Test]
    public function password_update_fails_with_wrong_current_password()
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'password' => bcrypt('correct-password'),
        ]);
        $user->assignRole('Tech');

        $response = $this->actingAs($user)
            ->post(route('tech.profile.security.password'), [
                'current_password' => 'wrong-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ]);

        $response->assertSessionHasErrors('current_password');
        $this->assertTrue(\Hash::check('correct-password', $user->fresh()->password));
    }

    #[Test]
    public function unauthenticated_user_cannot_access_security_settings()
    {
        $response = $this->get(route('tech.profile.security'));
        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function admin_can_view_2fa_enforcement_settings()
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin)
            ->get(route('tech.admin.user_management.2fa-settings'));

        $response->assertOk();
        $response->assertSee('2FA Enforcement');
    }

    #[Test]
    public function admin_can_enable_2fa_enforcement_for_roles()
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('Admin');

        // Ensure the settings exist with the shared common_settings schema.
        \DB::table('common_settings')->updateOrInsert(
            ['name' => 'enforce_two_factor'],
            ['type' => 'security', 'value' => '0', 'json' => null]
        );
        \DB::table('common_settings')->updateOrInsert(
            ['name' => 'enforce_two_factor_roles'],
            ['type' => 'security', 'value' => null, 'json' => '[]']
        );

        $response = $this->actingAs($admin)
            ->post(route('tech.admin.user_management.2fa-settings.update'), [
                'enforce_two_factor' => '1',
                'enforce_two_factor_roles' => ['Admin', 'Tech'],
            ]);

        $response->assertRedirect(route('tech.admin.user_management.2fa-settings'));

        $this->assertEquals('1', \DB::table('common_settings')->where('name', 'enforce_two_factor')->value('value'));
        $roles = json_decode(\DB::table('common_settings')->where('name', 'enforce_two_factor_roles')->value('json'), true);
        $this->assertContains('Admin', $roles);
        $this->assertContains('Tech', $roles);
    }
}
