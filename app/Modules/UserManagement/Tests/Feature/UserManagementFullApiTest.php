<?php

namespace App\Modules\UserManagement\Tests\Feature;

use App\Models\Core\User;
use App\Modules\UserManagement\Models\InviteToken;
use App\Modules\UserManagement\Models\UserManagementApiCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Real bearer/session workflows and adverse authorization/replay cases. */
class UserManagementFullApiTest extends TestCase
{
    use \App\Modules\UserManagement\Tests\Support\ValidatesUserManagementApiResponses;
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Tech', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE, 'password' => bcrypt('synthetic-password')]);
        $this->admin->assignRole('Admin');
        $this->token = $this->admin->createToken('UserManagement parity test', ['*'])->plainTextToken;
    }

    private function api(string $method, string $uri, array $data = [], array $headers = [])
    {
        Auth::forgetGuards();
        $response = $this->withToken($this->token)->json($method, '/api/v1/'.$uri, $data,
            array_merge(['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()], $headers));
        $this->validateApiResponse($method, $uri, $response);

        return $response;
    }

    private function etag(string $uri): string
    {
        return $this->api('GET', $uri)->assertOk()->headers->get('ETag');
    }

    public function test_own_profile_avatar_and_full_preferences_preserve_other_fields_and_weekly_plan(): void
    {
        Storage::fake('public');
        $context = $this->api('GET', 'users/context')->assertOk()->json('data.capabilities');
        $this->assertTrue($context['user.manage_roles']);
        $this->api('GET', 'users/me/shell')->assertOk()->assertJsonPath('data.integrations_status', 'not_configured');
        $this->api('PATCH', 'users/me/profile', ['work_phone' => '12345', 'profile_notes' => 'Own note'],
            ['If-Match' => $this->etag('users/me/profile')])->assertOk()->assertJsonPath('data.phone_work', '12345');
        $this->api('POST', 'users/me/profile/avatar', ['avatar' => UploadedFile::fake()->image('avatar.png')],
            ['If-Match' => $this->etag('users/me/profile')])->assertOk();
        $path = $this->admin->fresh()->profile->avatar_path;
        Storage::disk('public')->assertExists($path);
        $this->api('POST', 'users/me/profile/avatar', ['remove_avatar' => true],
            ['If-Match' => $this->etag('users/me/profile')])->assertOk()->assertJsonPath('data.profile.avatar_path', null);
        Storage::disk('public')->assertMissing($path);
        $plan = $this->admin->fresh()->profile->working_hours;
        $this->api('PATCH', 'users/me/preferences', ['theme' => 'dark'],
            ['If-Match' => $this->etag('users/me/preferences')])->assertOk()->assertJsonPath('data.theme', 'dark');
        $this->assertSame($plan, $this->admin->fresh()->profile->working_hours);
        $this->api('PATCH', 'users/me/preferences', ['workday_start' => '18:00', 'workday_end' => '09:00'],
            ['If-Match' => $this->etag('users/me/preferences')])->assertUnprocessable();
        $this->api('PATCH', 'users/me/profile', ['status' => User::STATUS_DISABLED],
            ['If-Match' => $this->etag('users/me/profile')])->assertUnprocessable();
        $this->api('GET', 'users/me/profile')->assertOk()->assertJsonPath('data.profile.profile_notes', 'Own note');
    }

    public function test_role_permission_lifecycle_and_membership_have_exact_readback_and_revocation_effects(): void
    {
        $role = $this->api('POST', 'users/administration/roles', ['name' => 'API role'])->assertCreated()->json('data.id');
        $permission = $this->api('POST', 'users/administration/permissions', ['name' => 'api.test.permission'])->assertCreated()->json('data.id');
        $roleUri = 'users/administration/roles/'.$role;
        $permUri = 'users/administration/permissions/'.$permission;
        $this->api('GET', 'users/administration/roles?q=API')->assertOk();
        $this->api('GET', $roleUri.'/permission-options?q=api.test')->assertOk();
        $this->api('GET', 'users/administration/permissions?q=api.test')->assertOk();
        $this->api('PUT', $roleUri.'/permissions/'.$permission, ['enabled' => true],
            ['If-Match' => $this->etag($roleUri)])->assertOk()->assertJsonPath('data.permissions.0.id', $permission);
        $this->api('PUT', $roleUri.'/permissions/'.$permission, ['enabled' => false],
            ['If-Match' => $this->etag($roleUri)])->assertOk()->assertJsonCount(0, 'data.permissions');
        $this->api('PATCH', $roleUri, ['name' => 'Renamed API role'], ['If-Match' => $this->etag($roleUri)])->assertOk();
        $this->api('PATCH', $permUri, ['name' => 'api.test.renamed'], ['If-Match' => $this->etag($permUri)])->assertOk();
        $this->api('DELETE', $roleUri, [], ['If-Match' => $this->etag($roleUri)])->assertOk()->assertJsonPath('data.deleted', true);
        $this->api('DELETE', $permUri, [], ['If-Match' => $this->etag($permUri)])->assertOk()->assertJsonPath('data.deleted', true);
        $this->api('GET', $roleUri)->assertNotFound();
        $this->api('GET', $permUri)->assertNotFound();
    }

    public function test_scope_does_not_grant_native_permissions_and_roles_do_not_grant_token_scopes(): void
    {
        $employee = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $employee->assignRole('Tech');
        $this->token = $employee->createToken('narrow', ['users.read', 'users.create', 'users.roles.write', 'users.permissions.write'])->plainTextToken;
        $this->api('GET', 'users')->assertForbidden();
        $this->api('POST', 'users', ['name' => 'Denied', 'email' => 'denied@example.test'])->assertForbidden();
        $this->api('POST', 'users/administration/roles', ['name' => 'Denied'])->assertForbidden();
        $this->api('POST', 'users/administration/permissions', ['name' => 'denied.permission'])->assertForbidden();
        $this->token = $this->admin->createToken('read only', ['users.read'])->plainTextToken;
        $this->api('POST', 'users', ['name' => 'Denied', 'email' => 'denied@example.test'])->assertForbidden();
        $this->api('GET', 'users')->assertOk();
        $this->assertDatabaseMissing('user_management', ['email' => 'denied@example.test']);
    }

    public function test_disabled_system_and_portal_only_users_cannot_use_internal_apis(): void
    {
        foreach ([['status' => User::STATUS_DISABLED], ['is_system_actor' => true], []] as $data) {
            $actor = User::factory()->create(array_merge(['status' => User::STATUS_ACTIVE], $data));
            $this->token = $actor->createToken('denied', ['*'])->plainTextToken;
            $this->api('GET', 'users/me/profile')->assertForbidden();
            $this->api('GET', 'users/context')->assertForbidden();
        }
    }

    public function test_narrow_create_permission_cannot_assign_roles_or_enlarge_authority(): void
    {
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $actor->givePermissionTo('user.create');
        $this->token = $actor->createToken('create', ['users.create', 'users.context.read'])->plainTextToken;
        $this->api('GET', 'users/context')->assertOk()->assertJsonCount(0, 'data.roles');
        $this->withoutHeader('Authorization')->actingAs($actor, 'web')->get(route('tech.admin.user_management.create'))
            ->assertOk()->assertDontSee('name="role"', false)->assertSee('A role manager can assign a role');
        $this->withToken($this->token);
        $role = Role::where('name', 'Admin')->firstOrFail();
        $this->api('POST', 'users', ['name' => 'Escalation', 'email' => 'escalation@example.test', 'role_ids' => [$role->id]])->assertForbidden();
        $this->assertDatabaseMissing('user_management', ['email' => 'escalation@example.test']);
    }

    public function test_durable_replay_collision_stale_state_and_owned_operation_lookup(): void
    {
        $headers = ['Idempotency-Key' => 'role-replay-key'];
        $first = $this->api('POST', 'users/administration/roles', ['name' => 'Replay role'], $headers)->assertCreated();
        $operation = $first->headers->get('X-Operation-Id');
        $this->api('POST', 'users/administration/roles', ['name' => 'Replay role'], $headers)->assertCreated()->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame(1, Role::where('name', 'Replay role')->count());
        $this->api('POST', 'users/administration/roles', ['name' => 'Changed'], $headers)->assertConflict();
        $this->api('GET', 'users/operations/'.$operation)->assertOk()->assertJsonPath('data.state', 'completed');
        $uri = 'users/administration/roles/'.$first->json('data.id');
        $etag = $this->etag($uri);
        $this->api('PATCH', $uri, ['name' => 'New name'], ['If-Match' => $etag])->assertOk();
        $this->api('PATCH', $uri, ['name' => 'Stale name'], ['If-Match' => $etag])->assertStatus(412);
        $this->api('PATCH', $uri, ['name' => 'Missing revision'])->assertStatus(428);
        $this->token = $this->admin->createToken('different token', ['*'])->plainTextToken;
        $this->api('GET', 'users/operations/'.$operation)->assertNotFound();
        $this->assertStringNotContainsString('Replay role', DB::table('user_management_api_commands')->where('id', $operation)->value('result'));
    }

    public function test_mfa_policy_uses_shared_settings_and_requires_current_native_rights(): void
    {
        $this->api('PATCH', 'users/administration/2fa-settings', ['enforce_two_factor' => true, 'enforce_two_factor_roles' => ['Tech']],
            ['If-Match' => $this->etag('users/administration/2fa-settings')])->assertOk()->assertJsonPath('data.enforce_two_factor', true);
        $this->api('GET', 'users/administration/2fa-settings')->assertOk()->assertJsonPath('data.enforce_two_factor_roles.0', 'Tech');
        $this->api('PATCH', 'users/administration/2fa-settings', ['enforce_two_factor_roles' => ['missing role']],
            ['If-Match' => $this->etag('users/administration/2fa-settings')])->assertUnprocessable();
    }

    public function test_security_bearer_cannot_manufacture_local_session_or_reveal_material(): void
    {
        $this->api('GET', 'users/me/security')->assertForbidden();
        $this->api('POST', 'users/me/security/material', ['current_password' => 'synthetic-password'])->assertForbidden();
        $this->api('POST', 'users/me/security/2fa/enable', ['current_password' => 'synthetic-password'])->assertForbidden();
        $this->api('PATCH', 'users/me/security/password', ['current_password' => 'synthetic-password', 'password' => 'synthetic-new-password', 'password_confirmation' => 'synthetic-new-password'])->assertForbidden();
        $this->assertDatabaseCount('user_management_api_commands', 0);
    }

    public function test_invitation_capability_expiry_single_use_activation_and_disabled_target(): void
    {
        $pending = User::factory()->create(['status' => User::STATUS_PENDING]);
        $pending->assignRole('Tech');
        $invite = InviteToken::generateFor($pending);
        $shown = $this->getJson('/api/v1/users/invitations/'.$invite->token)->assertOk()->assertJsonPath('data.valid', true)->assertHeader('Cache-Control', 'no-store, private');
        $this->validateApiResponse('GET', '/api/v1/users/invitations/'.$invite->token, $shown);
        $accepted = $this->postJson('/api/v1/users/invitations/'.$invite->token, ['password' => 'new-invite-password', 'password_confirmation' => 'new-invite-password'])->assertOk()->assertJsonPath('data.accepted', true);
        $this->validateApiResponse('POST', '/api/v1/users/invitations/'.$invite->token, $accepted);
        $this->assertTrue(Hash::check('new-invite-password', $pending->fresh()->password));
        $this->assertNotNull($invite->fresh()->used_at);
        $this->postJson('/api/v1/users/invitations/'.$invite->token, ['password' => 'new-invite-password', 'password_confirmation' => 'new-invite-password'])->assertNotFound();
        $disabled = User::factory()->create(['status' => User::STATUS_DISABLED]);
        $invite = InviteToken::generateFor($disabled);
        $this->getJson('/api/v1/users/invitations/'.$invite->token)->assertNotFound();
        $expired = InviteToken::generateFor(User::factory()->create(['status' => User::STATUS_PENDING]), -1);
        $this->getJson('/api/v1/users/invitations/'.$expired->token)->assertNotFound();
        $this->assertDatabaseCount('user_management_api_commands', 0);
    }

    public function test_every_legacy_user_method_and_alias_has_typed_exact_results_and_safe_invite_replay(): void
    {
        Storage::fake('public');
        $tech = Role::where('name', 'Tech')->firstOrFail();
        $created = $this->api('POST', 'users', ['name' => 'Typed user', 'email' => 'typed-user@example.test', 'role_ids' => [$tech->id]])->assertCreated();
        $uri = 'users/'.$created->json('data.id');
        $this->api('GET', 'users/roles')->assertOk();
        $this->api('GET', 'users')->assertOk();
        $this->api('GET', $uri)->assertOk();
        $this->api('POST', $uri.'/avatar', ['avatar' => UploadedFile::fake()->image('admin-avatar.png')], ['If-Match' => $this->etag($uri)])->assertOk();
        $this->api('PUT', $uri, ['phone_work' => '123'])->assertOk()->assertJsonPath('data.phone_work', '123');
        $this->api('PATCH', $uri, ['work_phone' => null])->assertOk()->assertJsonPath('data.phone_work', null);
        $this->api('POST', $uri.'/roles', ['role_ids' => [$tech->id]])->assertOk();
        $headers = ['Idempotency-Key' => 'typed-invite-replay'];
        $this->api('POST', $uri.'/invite', [], $headers)->assertOk()->assertJsonPath('data.delivery_status', 'queued');
        $count = InviteToken::where('user_id', $created->json('data.id'))->count();
        $this->api('POST', $uri.'/invite', [], $headers)->assertOk()->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($count, InviteToken::where('user_id', $created->json('data.id'))->count());
        $this->api('POST', $uri.'/invitations', [], ['If-Match' => $this->etag($uri)])->assertOk()->assertJsonPath('data.delivery_status', 'queued');
        $this->api('POST', $uri.'/status', ['status' => User::STATUS_ACTIVE])->assertOk()->assertJsonPath('data.status', User::STATUS_ACTIVE);
    }

    public function test_retained_work_plan_and_calendar_preferences_use_their_own_revision_contracts(): void
    {
        config(['workday.enabled' => true]);
        $this->admin->givePermissionTo('calendar.view');
        $plan = $this->api('GET', 'users/me/work-plan')->assertOk()->json('data');
        $this->api('PATCH', 'users/me/work-plan', ['revision' => $plan['revision'], 'timezone' => 'Europe/Oslo',
            'working_hours' => \App\Modules\UserManagement\Support\UserProfileData::defaultWorkingHours(), 'accept_calendar_conflicts' => true])->assertOk();
        $preferences = $this->api('GET', 'users/me/calendar-preferences')->assertOk()->json('data');
        $this->api('PATCH', 'users/me/calendar-preferences', ['revision' => $preferences['revision'], 'default_calendar_view' => 'month'])->assertOk();
    }

    public function test_real_session_security_lifecycle_and_password_require_local_verification_without_receipts(): void
    {
        config(['sanctum.stateful' => ['localhost']]);
        $this->withoutHeader('Authorization');
        $this->actingAs($this->admin, 'web')->withHeaders(['Origin' => 'http://localhost']);
        $call = function (string $method, string $path, array $data = []) {
            $response = $this->json($method, '/api/v1/users/me/security'.$path, $data);
            $this->validateApiResponse($method, '/api/v1/users/me/security'.$path, $response);

            return $response;
        };
        $call('GET', '')->assertOk()->assertJsonPath('data.two_factor_enabled', false);
        $call('POST', '/2fa/enable', ['current_password' => 'wrong-password'])->assertForbidden();
        $call('POST', '/2fa/enable', ['current_password' => 'synthetic-password'])->assertOk();
        $material = $call('POST', '/material', ['current_password' => 'synthetic-password'])->assertOk()->json('data');
        $this->assertNotEmpty($material['setup_key']);
        $this->assertCount(8, $material['recovery_codes']);
        $code = (new \PragmaRX\Google2FA\Google2FA)->getCurrentOtp($material['setup_key']);
        $call('POST', '/2fa/confirm', ['current_password' => 'synthetic-password', 'code' => $code])->assertOk()->assertJsonPath('data.two_factor_confirmed', true);
        $oldCodes = $this->admin->fresh()->recoveryCodes();
        $call('POST', '/recovery-codes', ['current_password' => 'synthetic-password', 'code' => $code])->assertOk();
        $this->assertNotSame($oldCodes, $this->admin->fresh()->recoveryCodes());
        $call('POST', '/2fa/disable', ['current_password' => 'synthetic-password', 'code' => $code])->assertForbidden();
        // Use the next accepted clock-skew counter; do not reset Fortify replay protection.
        $engine = new \PragmaRX\Google2FA\Google2FA;
        $nextCode = $engine->oathTotp($material['setup_key'], $engine->getTimestamp() + 1);
        $call('POST', '/2fa/disable', ['current_password' => 'synthetic-password', 'code' => $nextCode])->assertOk()->assertJsonPath('data.two_factor_enabled', false);
        $call('PATCH', '/password', ['current_password' => 'synthetic-password', 'password' => 'synthetic-new-password', 'password_confirmation' => 'synthetic-new-password'])->assertOk()->assertJsonPath('data.password_updated', true);
        $this->assertTrue(Hash::check('synthetic-new-password', $this->admin->fresh()->password));
        $this->assertDatabaseCount('user_management_api_commands', 0);
        $this->assertNull(request()->input('password'));
        $this->assertNull(request()->input('current_password'));
    }

    public function test_failed_receipt_persistence_rolls_back_profile_and_preserves_existing_avatar(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('user-avatars/existing.png', 'synthetic-old-avatar');
        $this->admin->profile()->create(['timezone' => 'Europe/Oslo', 'avatar_path' => 'user-avatars/existing.png']);
        $etag = $this->etag('users/me/profile');
        UserManagementApiCommand::creating(fn () => throw new \RuntimeException('Synthetic command failure'));
        $this->api('POST', 'users/me/profile/avatar', ['avatar' => UploadedFile::fake()->image('replacement.png')],
            ['If-Match' => $etag])->assertStatus(500);
        $this->assertSame('user-avatars/existing.png', $this->admin->fresh()->profile->avatar_path);
        Storage::disk('public')->assertExists('user-avatars/existing.png');
        $this->assertSame(['user-avatars/existing.png'], Storage::disk('public')->allFiles('user-avatars'));
        $this->assertDatabaseCount('user_management_api_commands', 0);
    }

    public function test_original_authority_is_rechecked_on_replay_and_result_lookup(): void
    {
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $actor->givePermissionTo('user.manage_roles');
        $this->token = $actor->createToken('limited role manager', ['users.roles.write', 'users.roles.read', 'users.operations.read'])->plainTextToken;
        $role = Role::where('name', 'Tech')->firstOrFail();
        $this->api('GET', 'users/administration/roles/'.$role->id.'/permission-options')->assertOk();
        $this->api('GET', 'users/administration/permissions')->assertForbidden();
        $headers = ['Idempotency-Key' => 'original-authority-replay'];
        $first = $this->api('POST', 'users/administration/roles', ['name' => 'Narrow role'], $headers)->assertCreated();
        $operation = $first->headers->get('X-Operation-Id');
        $actor->revokePermissionTo('user.manage_roles');
        $this->api('POST', 'users/administration/roles', ['name' => 'Narrow role'], $headers)->assertForbidden();
        $this->api('GET', 'users/operations/'.$operation)->assertForbidden();
        $this->assertSame(1, Role::where('name', 'Narrow role')->count());
    }

    public function test_after_commit_failure_retains_unknown_receipt_and_never_repeats_the_mutation(): void
    {
        UserManagementApiCommand::created(function () {
            DB::afterCommit(fn () => throw new \RuntimeException('Synthetic post-commit failure'));
        });
        $headers = ['Idempotency-Key' => 'post-commit-reconciliation'];
        $first = $this->api('POST', 'users/administration/roles', ['name' => 'Committed role'], $headers)->assertAccepted()->assertJsonPath('data.state', 'unknown');
        $this->api('POST', 'users/administration/roles', ['name' => 'Committed role'], $headers)->assertAccepted()->assertHeader('X-Idempotent-Replay', 'true');
        $this->api('GET', 'users/operations/'.$first->headers->get('X-Operation-Id'))->assertOk()->assertJsonPath('data.state', 'unknown');
        $this->assertSame(1, Role::where('name', 'Committed role')->count());
    }

    public function test_real_csrf_middleware_protects_first_party_security_mutations(): void
    {
        config(['sanctum.stateful' => ['localhost'],
            'sanctum.middleware.validate_csrf_token' => EnforcedUserManagementCsrf::class]);
        $this->withoutHeader('Authorization');
        $this->actingAs($this->admin, 'web')->withSession(['_token' => 'synthetic-csrf-token'])
            ->withHeaders(['Origin' => 'http://localhost']);
        $this->postJson('/api/v1/users/me/security/2fa/enable', ['current_password' => 'synthetic-password'])->assertStatus(419);
        $this->assertNull($this->admin->fresh()->two_factor_secret);
        $response = $this->withHeaders(['X-CSRF-TOKEN' => 'synthetic-csrf-token'])
            ->postJson('/api/v1/users/me/security/2fa/enable', ['current_password' => 'synthetic-password'])->assertOk();
        $this->validateApiResponse('POST', 'users/me/security/2fa/enable', $response);
        $this->assertNotNull($this->admin->fresh()->two_factor_secret);
        $this->assertDatabaseCount('user_management_api_commands', 0);
    }
}

/** Enable the actual framework CSRF check that Laravel ordinarily skips in tests. */
class EnforcedUserManagementCsrf extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
