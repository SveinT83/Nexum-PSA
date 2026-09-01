<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailLiveProjectionChange;
use App\Modules\Email\Models\EmailLiveProjectionStream;
use App\Modules\Email\Services\EmailLiveInvalidator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class EmailLiveRuntimeQuarantineTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function disabled_live_scaffold_does_not_block_a_grant_for_a_new_account(): void
    {
        config()->set('email_live.enabled', false);
        $user = User::factory()->create();
        $account = EmailAccount::query()->create([
            'address' => 'live-quarantine@example.test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => false,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
        ]);

        $this->assertDatabaseHas('email_live_account_authority_states', [
            'email_account_id' => $account->id,
            'audience_generation' => 1,
        ]);
        $this->assertDatabaseHas('email_live_user_access_states', [
            'user_id' => $user->id,
            'recompute_status' => 'pending',
        ]);

        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
            'granted_at' => now(),
        ]);

        $this->assertDatabaseHas('email_account_user_grants', [
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'can_view' => true,
        ]);
    }

    #[Test]
    public function quarantine_refuses_to_remove_authority_guards_while_live_mode_is_enabled(): void
    {
        config()->set('email_live.enabled', true);
        $migration = require database_path(
            'migrations/2026_08_21_120000_quarantine_incomplete_email_live_authority_guards.php',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Disable Email live invalidation');

        $migration->up();
    }

    #[Test]
    public function stream_versions_and_acknowledgements_may_advance_twice_in_one_second(): void
    {
        config()->set('email_live.enabled', true);
        Queue::fake();
        CarbonImmutable::setTestNow('2026-08-21 12:00:00');
        $user = User::factory()->create();

        try {
            DB::transaction(function () use ($user): void {
                $invalidator = app(EmailLiveInvalidator::class);

                foreach (['first', 'second'] as $operation) {
                    $invalidator->record([
                        'user' => [$user->id => [EmailLiveProjectionChange::TYPE_PERSONAL_STATE]],
                        'idempotency_key' => "same-second:{$operation}",
                    ]);
                }
            });

            $stream = EmailLiveProjectionStream::query()->where('user_id', $user->id)->firstOrFail();
            $this->assertSame(2, $stream->current_version);
            $this->assertSame(2, $stream->changes()->count());

            $stream->update(['acknowledged_version' => 1, 'acknowledged_at' => now()]);
            $stream->update(['acknowledged_version' => 2, 'acknowledged_at' => now()]);

            $this->assertSame(2, $stream->fresh()->acknowledged_version);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
