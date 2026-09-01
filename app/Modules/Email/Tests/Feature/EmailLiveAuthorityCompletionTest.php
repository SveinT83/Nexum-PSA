<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailMailboxDelegation;
use App\Modules\Email\Models\EmailLiveUserAccessState;
use App\Modules\Email\Livewire\Tech\MailWorkspace;
use App\Modules\Email\Services\EmailLiveAccessRecomputeService;
use App\Modules\Email\Services\EmailLiveAuthorityBoundaryService;
use App\Modules\Email\Services\EmailLiveAuthorityCoordinator;
use App\Modules\Email\Services\EmailLiveCurrentViewProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Livewire\Livewire;

class EmailLiveAuthorityCompletionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function account_authority_mutation_resets_exact_users_and_recompute_seals_in_bounded_phases(): void
    {
        config()->set('email_live.enabled', false);
        $owner = User::factory()->create();
        $delegate = User::factory()->create();
        $account = EmailAccount::query()->create([
            'address' => 'authority-completion@example.test',
            'account_kind' => EmailAccount::KIND_PERSONAL,
            'owner_id' => $owner->id,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
        ]);

        $generation = DB::transaction(fn (): int => app(EmailLiveAuthorityCoordinator::class)
            ->prepareAccountMutation($account, [$owner->id, $delegate->id]));

        $this->assertSame(2, $generation);
        $this->assertDatabaseHas('email_live_account_authority_states', [
            'email_account_id' => $account->id,
            'audience_generation' => 2,
            'owner_user_id' => $owner->id,
        ]);
        $states = EmailLiveUserAccessState::query()->whereIn('user_id', [$owner->id, $delegate->id])->get();
        $this->assertCount(2, $states);
        $this->assertTrue($states->every(fn (EmailLiveUserAccessState $state): bool => $state->recompute_status === 'pending'));

        $service = app(EmailLiveAccessRecomputeService::class);
        foreach ($states as $state) {
            $this->assertTrue($service->processNextPage($state->id));
            $this->assertSame(EmailLiveUserAccessState::PHASE_BREAK_GLASS, $state->fresh()->recompute_phase);
            $this->assertTrue($service->processNextPage($state->id));
            $this->assertSame(EmailLiveUserAccessState::STATUS_SEALED, $state->fresh()->recompute_status);
        }
    }


    #[Test]
    public function scheduled_delegation_boundaries_advance_authority_once_at_start_and_expiry(): void
    {
        $now = now()->startOfMinute();
        $this->travelTo($now);
        $owner = User::factory()->create();
        $delegate = User::factory()->create();
        $account = EmailAccount::query()->create([
            'address' => 'scheduled-boundary@example.test',
            'account_kind' => EmailAccount::KIND_PERSONAL,
            'owner_id' => $owner->id,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
        ]);

        $generation = DB::transaction(fn (): int => app(EmailLiveAuthorityCoordinator::class)
            ->prepareAccountMutation($account, [$delegate->id]));
        $delegation = EmailMailboxDelegation::query()->forceCreate([
            'email_account_id' => $account->id,
            'owner_id' => $owner->id,
            'delegate_id' => $delegate->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
            'can_view_raw_source' => false,
            'reason' => 'Scheduled cover',
            'starts_at' => $now->copy()->addMinutes(5),
            'expires_at' => $now->copy()->addMinutes(10),
            'created_by' => $owner->id,
            'email_live_enable_generation' => $generation,
        ]);

        $this->travelTo($now->copy()->addMinutes(5));
        $this->assertSame(1, app(EmailLiveAuthorityBoundaryService::class)->processDue());
        $this->assertNotNull($delegation->fresh()->email_live_start_invalidated_at);
        $this->assertSame(3, (int) $delegation->fresh()->email_live_enable_generation);
        $this->assertSame(0, app(EmailLiveAuthorityBoundaryService::class)->processDue());

        $this->travelTo($now->copy()->addMinutes(10));
        $this->assertSame(1, app(EmailLiveAuthorityBoundaryService::class)->processDue());
        $current = $delegation->fresh();
        $this->assertNotNull($current->email_live_expiry_invalidated_at);
        $this->assertSame(4, (int) $current->email_live_enable_generation);
        $this->assertSame(4, (int) DB::table('email_live_account_authority_states')
            ->where('email_account_id', $account->id)->value('audience_generation'));
    }

    #[Test]
    public function bounded_current_view_projects_only_allowlisted_authority_and_explicit_count_metadata(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $permission = Permission::findOrCreate('email.inbox_view', 'web');
        $user->givePermissionTo($permission);
        $account = EmailAccount::query()->create([
            'address' => 'bounded-current-view@example.test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
        ]);
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
            'granted_at' => now(),
        ]);
        EmailAccount::query()->create([
            'address' => 'not-authorized@example.test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
        ]);

        $snapshot = app(EmailLiveCurrentViewProjector::class)->project($user, 'inbox', '', '');

        $this->assertSame([$account->id], $snapshot['account_ids']);
        $this->assertSame([$account->id], $snapshot['ordinary_account_ids']);
        $this->assertSame([
            'inbox' => 0,
            'drafts' => 0,
            'all' => 0,
            'provider_unread' => 0,
            'unread_for_me' => 0,
        ], $snapshot['stats']);
        $this->assertSame([
            'inbox' => false,
            'drafts' => false,
            'all' => false,
            'provider_unread' => false,
            'unread_for_me' => false,
        ], $snapshot['stats_truncated']);
        $this->assertFalse($snapshot['navigation_truncated']);
    }

    #[Test]
    public function forced_live_catch_up_renders_from_the_bounded_snapshot_without_full_navigation(): void
    {
        config()->set('email_live.enabled', true);
        config()->set('email_live.runtime_approved', true);
        config()->set('email_live.allowed_origins', ['https://nexum.example.test']);
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.options.host', 'mail-live.example.test');
        config()->set('broadcasting.connections.reverb.options.port', 443);
        config()->set('broadcasting.connections.reverb.options.scheme', 'https');
        config()->set('reverb.servers.reverb.host', '127.0.0.1');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(Permission::findOrCreate('email.inbox_view', 'web'));
        $account = EmailAccount::query()->create([
            'address' => 'bounded-livewire@example.test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
        ]);
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
            'granted_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(MailWorkspace::class)
            ->assertSet('liveEnabled', true)
            ->call('catchUpInvalidation', true)
            ->assertSet('liveBoundedRefreshPending', false)
            ->assertSet('liveCurrentViewSnapshot.account_ids', [$account->id])
            ->assertSet('liveCurrentViewSnapshot.navigation_truncated', false);
    }
}
