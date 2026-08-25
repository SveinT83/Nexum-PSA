<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Jobs\EmailLivePublisher;
use App\Modules\Email\Models\EmailLiveProjectionChange;
use App\Modules\Email\Models\EmailLiveProjectionDelivery;
use App\Modules\Email\Models\EmailLiveProjectionPublication;
use App\Modules\Email\Models\EmailLiveProjectionStream;
use App\Modules\Email\Models\EmailLiveUserAccessState;
use App\Modules\Email\Services\EmailLiveCatchUpService;
use App\Modules\Email\Services\EmailLiveInvalidator;
use App\Modules\Email\Services\EmailLivePublisherService;
use App\Modules\Email\Services\EmailLiveRetentionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class EmailLivePublisherStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureRuntime();
        Queue::fake([EmailLivePublisher::class]);
        Event::fake();
    }

    #[Test]
    public function direct_user_publication_never_invents_browser_acknowledgement_or_sealing(): void
    {
        $user = User::factory()->create();
        $stream = EmailLiveProjectionStream::query()->create([
            'stream_type' => EmailLiveProjectionStream::TYPE_USER,
            'user_id' => $user->id,
            'current_version' => 0,
            'oldest_retained_version' => 1,
        ]);
        $stream->update(['current_version' => 1, 'last_changed_at' => now()]);
        $change = $this->change($stream, 1, 'direct-user-publication');

        app(EmailLivePublisherService::class)->publish($change);

        $change->refresh();
        $stream->refresh();
        $this->assertSame(EmailLiveProjectionChange::STATUS_PUBLISHED, $change->publication_status);
        $this->assertNotNull($change->published_at);
        $this->assertNull($change->sealed_at);
        $this->assertNull($change->retention_ready_at);
        $this->assertSame(0, $stream->acknowledged_version);
    }

    #[Test]
    public function source_snapshot_and_dispatch_share_the_outer_commit_boundary(): void
    {
        $user = User::factory()->create();
        $invalidator = app(EmailLiveInvalidator::class);

        DB::transaction(fn () => $invalidator->record([
            'global' => [EmailLiveProjectionChange::TYPE_TAXONOMY],
            'idempotency_key' => 'global-snapshot-committed',
        ]));

        $change = EmailLiveProjectionChange::query()->sole();
        $publication = EmailLiveProjectionPublication::query()->sole();
        $global = DB::table('email_live_global_authority_states')->where('id', 1)->first();

        $this->assertSame((int) $change->id, (int) $publication->source_change_id);
        $this->assertSame((int) $user->id, (int) $publication->active_user_through_id);
        $this->assertSame((int) $global->active_user_generation, $publication->global_active_user_generation);
        $this->assertSame((int) $global->content_audience_generation, $publication->global_content_audience_generation);
        $this->assertSame((int) $global->content_ability_generation, $publication->global_content_ability_generation);
        $this->assertSame($change->created_at?->format('Y-m-d H:i:s'), $publication->source_at?->format('Y-m-d H:i:s'));

        try {
            DB::transaction(function () use ($invalidator): void {
                $invalidator->record([
                    'global' => [EmailLiveProjectionChange::TYPE_ACCOUNT_STATE],
                    'idempotency_key' => 'global-snapshot-rolled-back',
                ]);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        $this->assertDatabaseCount('email_live_projection_changes', 1);
        $this->assertDatabaseCount('email_live_projection_publications', 1);
        $this->assertSame(1, $change->stream->fresh()->current_version);
        Queue::assertPushed(EmailLivePublisher::class, 1);
    }

    #[Test]
    public function global_fanout_advances_over_at_most_one_hundred_raw_candidates(): void
    {
        $users = collect();
        foreach (range(1, 105) as $_) {
            $user = User::factory()->create(['status' => User::STATUS_DISABLED]);
            $this->accessState($user);
            $users->push($user);
        }

        $change = $this->globalSource('bounded-global-page');
        $service = app(EmailLivePublisherService::class);
        $service->publish($change);
        $publication = $change->publication()->firstOrFail();

        $this->invoke($service, 'processPublicationPage', [(int) $publication->id]);

        $publication->refresh();
        $expectedCursor = (int) $users->pluck('id')->sort()->values()->get(99);
        $this->assertSame(EmailLiveProjectionPublication::STATUS_PENDING, $publication->status);
        $this->assertSame(EmailLiveProjectionPublication::PHASE_ACTIVE_USERS, $publication->phase);
        $this->assertSame($expectedCursor, $publication->candidate_cursor_id);
        $this->assertSame(1, $publication->page_count);
        $this->assertSame(100, $publication->deliveries()->count());
        $this->assertSame(100, $publication->deliveries()->distinct('user_id')->count('user_id'));
    }

    #[Test]
    public function exhausted_delivery_is_blocked_without_false_source_sealing(): void
    {
        CarbonImmutable::setTestNow('2026-08-24 12:00:00');

        try {
            $user = User::factory()->create(['status' => User::STATUS_DISABLED]);
            $this->accessState($user);
            $change = $this->globalSource('blocked-global-delivery');
            $service = app(EmailLivePublisherService::class);
            $service->publish($change);
            $publication = $change->publication()->firstOrFail();
            $page = $this->invoke($service, 'claimPublicationPage', [(int) $publication->id]);
            $this->assertIsArray($page);
            $this->invoke($service, 'commitPublicationPage', [
                (int) $publication->id,
                $page['token'],
                $page['candidates'],
            ]);
            $publication->refresh();
            $this->assertSame(
                EmailLiveProjectionPublication::STATUS_SEALED,
                $publication->status,
                (string) $publication->error_code,
            );
            $delivery = $publication->deliveries()->sole();

            foreach (range(1, 3) as $attempt) {
                $claimed = $this->invoke($service, 'claimDelivery', [(int) $delivery->id]);
                $this->assertInstanceOf(EmailLiveProjectionDelivery::class, $claimed);
                $this->invoke($service, 'failDelivery', [
                    (int) $delivery->id,
                    (string) $claimed->claim_token,
                ]);
                CarbonImmutable::setTestNow(now()->addSeconds(20));
            }

            $delivery->refresh();
            $change->refresh();
            $publication->refresh();
            $this->assertSame(EmailLiveProjectionDelivery::STATUS_BLOCKED, $delivery->status);
            $this->assertSame(3, $delivery->attempt_count);
            $this->assertSame(EmailLiveProjectionChange::STATUS_BLOCKED, $change->publication_status);
            $this->assertNull($change->sealed_at);
            $this->assertNull($change->retention_ready_at);
            $this->assertSame('pending', $publication->delivery_summary_status);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    #[Test]
    public function terminal_delivery_summary_is_required_before_source_sealing(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_DISABLED]);
        $this->accessState($user);
        $change = $this->globalSource('sealed-global-delivery');

        app(EmailLivePublisherService::class)->publishPending();

        $change->refresh();
        $publication = $change->publication()->firstOrFail();
        $delivery = $publication->deliveries()->sole();
        $this->assertSame(EmailLiveProjectionDelivery::STATUS_SUPPRESSED, $delivery->status);
        $this->assertSame(EmailLiveProjectionPublication::STATUS_SEALED, $publication->status);
        $this->assertSame('sealed', $publication->delivery_summary_status);
        $this->assertSame(1, $publication->delivery_count);
        $this->assertSame(0, $publication->delivery_appended_count);
        $this->assertSame(1, $publication->delivery_suppressed_count);
        $this->assertSame(EmailLiveProjectionChange::STATUS_SEALED, $change->publication_status);
        $this->assertNotNull($change->retention_ready_at);
        $this->assertSame(1, $change->compact_delivery_count);
        $this->assertSame(1, $change->compact_suppressed_count);
    }

    #[Test]
    public function missing_recipient_authority_blocks_finitely_without_cursor_advance(): void
    {
        CarbonImmutable::setTestNow('2026-08-24 14:00:00');

        try {
            User::factory()->create(['status' => User::STATUS_DISABLED]);
            $change = $this->globalSource('missing-recipient-authority');
            $service = app(EmailLivePublisherService::class);
            $service->publish($change);
            $publication = $change->publication()->firstOrFail();

            foreach (range(1, 3) as $_) {
                $this->invoke($service, 'processPublicationPage', [(int) $publication->id]);
                CarbonImmutable::setTestNow(now()->addSeconds(20));
            }

            $publication->refresh();
            $change->refresh();
            $this->assertSame(EmailLiveProjectionPublication::STATUS_BLOCKED, $publication->status);
            $this->assertSame(0, $publication->candidate_cursor_id);
            $this->assertSame(3, $publication->attempt_count);
            $this->assertDatabaseCount('email_live_projection_deliveries', 0);
            $this->assertSame(EmailLiveProjectionChange::STATUS_BLOCKED, $change->publication_status);
            $this->assertNull($change->sealed_at);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    #[Test]
    public function signed_catch_up_receipt_bounds_ack_and_no_change_skip_render(): void
    {
        $user = User::factory()->create();
        $this->sealAccessState($this->accessState($user));
        DB::transaction(fn () => app(EmailLiveInvalidator::class)->record([
            'user' => [$user->id => [EmailLiveProjectionChange::TYPE_PERSONAL_STATE]],
            'conversations' => [17],
            'placements' => [29],
            'idempotency_key' => 'signed-catch-up',
        ]));

        $service = app(EmailLiveCatchUpService::class);
        $result = $service->catchUp($user, '0', '1', '1');

        $this->assertSame('1', $result['to_version']);
        $this->assertSame([17], $result['conversation_ids']);
        $this->assertSame([29], $result['placement_ids']);
        $this->assertFalse($result['truncated']);
        $this->assertSame('stream_changed', $result['reason']);

        $service->acknowledgeAppliedVersion($user, '1', '1', '1', 'tampered');
        $stream = EmailLiveProjectionStream::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(0, $stream->fresh()->acknowledged_version);

        $service->acknowledgeAppliedVersion(
            $user,
            '1',
            '1',
            '1',
            $result['applied_receipt'],
        );
        $this->assertSame(1, $stream->fresh()->acknowledged_version);

        $unchanged = $service->catchUp($user, '1', '1', '1');
        $this->assertTrue($unchanged['skip_render']);
        $this->assertSame('unchanged', $unchanged['reason']);

        $invalid = $service->catchUp($user, '1e0', '1', '1');
        $this->assertTrue($invalid['refresh']);
        $this->assertTrue($invalid['truncated']);
        $this->assertSame([], $invalid['conversation_ids']);
        $this->assertSame('invalid_client_state', $invalid['reason']);
    }

    #[Test]
    public function catch_up_overflow_is_generic_and_capped(): void
    {
        config()->set('email_live.catch_up_version_limit', 2);
        $user = User::factory()->create();
        $this->sealAccessState($this->accessState($user));

        DB::transaction(function () use ($user): void {
            foreach (range(1, 3) as $operation) {
                app(EmailLiveInvalidator::class)->record([
                    'user' => [$user->id => [EmailLiveProjectionChange::TYPE_PERSONAL_STATE]],
                    'conversations' => [$operation],
                    'idempotency_key' => "catch-up-overflow:{$operation}",
                ]);
            }
        });

        $result = app(EmailLiveCatchUpService::class)->catchUp($user, '0', '1', '1');

        $this->assertSame('3', $result['to_version']);
        $this->assertSame('version_window_exceeded', $result['reason']);
        $this->assertTrue($result['truncated']);
        $this->assertSame([], $result['conversation_ids']);
        $this->assertContains(EmailLiveProjectionChange::TYPE_AUTHORIZATION, $result['change_types']);
    }

    #[Test]
    public function retention_prunes_only_the_acknowledged_terminal_prefix(): void
    {
        CarbonImmutable::setTestNow('2026-08-20 08:00:00');

        try {
            $user = User::factory()->create();
            $this->sealAccessState($this->accessState($user));
            DB::transaction(function () use ($user): void {
                foreach ([1, 2] as $operation) {
                    app(EmailLiveInvalidator::class)->record([
                        'user' => [$user->id => [EmailLiveProjectionChange::TYPE_PERSONAL_STATE]],
                        'idempotency_key' => "retention-prefix:{$operation}",
                    ]);
                }
            });

            $publisher = app(EmailLivePublisherService::class);
            EmailLiveProjectionChange::query()->orderBy('version')->get()
                ->each(fn (EmailLiveProjectionChange $change) => $publisher->publish($change));
            CarbonImmutable::setTestNow('2026-08-24 12:00:00');

            $catchUp = app(EmailLiveCatchUpService::class);
            $receipt = $catchUp->receipt($user, '1', '1', '1');
            $catchUp->acknowledgeAppliedVersion($user, '1', '1', '1', $receipt);
            $result = app(EmailLiveRetentionService::class)->prune(10);

            $stream = EmailLiveProjectionStream::query()->where('user_id', $user->id)->firstOrFail();
            $this->assertSame(['changes' => 1, 'publications' => 0, 'deliveries' => 0], $result);
            $this->assertSame(2, $stream->fresh()->oldest_retained_version);
            $this->assertDatabaseMissing('email_live_projection_changes', [
                'stream_id' => $stream->id,
                'version' => 1,
            ]);
            $this->assertDatabaseHas('email_live_projection_changes', [
                'stream_id' => $stream->id,
                'version' => 2,
                'publication_status' => EmailLiveProjectionChange::STATUS_PUBLISHED,
            ]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function configureRuntime(): void
    {
        config()->set('email_live.enabled', true);
        config()->set('email_live.runtime_approved', true);
        config()->set('email_live.allowed_origins', ['https://nexum.example.test']);
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.options.host', 'mail-live.example.test');
        config()->set('broadcasting.connections.reverb.options.port', 443);
        config()->set('broadcasting.connections.reverb.options.scheme', 'https');
        config()->set('reverb.servers.reverb.host', '127.0.0.1');
    }

    private function globalSource(string $idempotencyKey): EmailLiveProjectionChange
    {
        DB::transaction(fn () => app(EmailLiveInvalidator::class)->record([
            'global' => [EmailLiveProjectionChange::TYPE_TAXONOMY],
            'idempotency_key' => $idempotencyKey,
        ]));

        return EmailLiveProjectionChange::query()
            ->where('idempotency_key', hash('sha256', implode(':', [
                hash('sha256', $idempotencyKey),
                EmailLiveProjectionStream::TYPE_GLOBAL,
                '1',
            ])))
            ->firstOrFail();
    }

    private function accessState(User $user): EmailLiveUserAccessState
    {
        return EmailLiveUserAccessState::query()->create([
            'user_id' => $user->id,
            'authorization_epoch' => 1,
            'content_ability_enable_generation' => 1,
            'global_authorization_generation_seen' => 1,
            'recompute_status' => EmailLiveUserAccessState::STATUS_PENDING,
            'recompute_phase' => EmailLiveUserAccessState::PHASE_DELEGATIONS,
            'delegation_through_id' => 0,
            'break_glass_through_id' => 0,
            'recompute_cursor_id' => 0,
            'recompute_boundary_at' => now(),
        ]);
    }

    private function sealAccessState(EmailLiveUserAccessState $state): void
    {
        $state->update([
            'recompute_status' => EmailLiveUserAccessState::STATUS_RUNNING,
            'claim_token' => hash('sha256', "access-delegations:{$state->id}"),
            'page_through_id' => 0,
            'page_row_count' => 0,
            'attempt_count' => 1,
            'last_attempt_at' => now(),
        ]);
        $state->update([
            'recompute_status' => EmailLiveUserAccessState::STATUS_PENDING,
            'recompute_phase' => EmailLiveUserAccessState::PHASE_BREAK_GLASS,
            'recompute_cursor_id' => 0,
            'claim_token' => null,
            'page_through_id' => null,
            'page_row_count' => null,
            'page_count' => 1,
        ]);
        $state->update([
            'recompute_status' => EmailLiveUserAccessState::STATUS_RUNNING,
            'claim_token' => hash('sha256', "access-break-glass:{$state->id}"),
            'page_through_id' => 0,
            'page_row_count' => 0,
            'attempt_count' => 2,
            'last_attempt_at' => now(),
        ]);
        $state->update([
            'recompute_status' => EmailLiveUserAccessState::STATUS_SEALED,
            'recompute_phase' => null,
            'recompute_boundary_at' => null,
            'claim_token' => null,
            'page_through_id' => null,
            'page_row_count' => null,
            'page_count' => 2,
            'completed_at' => now(),
        ]);
    }

    private function change(
        EmailLiveProjectionStream $stream,
        int $version,
        string $key,
    ): EmailLiveProjectionChange {
        return EmailLiveProjectionChange::query()->create([
            'stream_id' => $stream->id,
            'version' => $version,
            'idempotency_key' => hash('sha256', $key),
            'change_types_json' => [EmailLiveProjectionChange::TYPE_PERSONAL_STATE],
            'conversation_ids_json' => null,
            'placement_ids_json' => null,
            'conversation_id_count' => 0,
            'placement_id_count' => 0,
            'publication_status' => EmailLiveProjectionChange::STATUS_PENDING,
            'available_at' => now(),
        ]);
    }

    private function invoke(object $target, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }
}
