<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Jobs\EmailLivePublisher;
use App\Modules\Email\Models\EmailLiveProjectionChange;
use App\Modules\Email\Models\EmailLiveProjectionStream;
use App\Modules\Email\Services\EmailLiveInvalidator;
use App\Modules\Email\Services\EmailLivePublisherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class EmailLiveInvalidatorRuntimeGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_runtime_is_a_safe_no_op_without_an_outer_transaction(): void
    {
        config()->set('email_live.enabled', false);

        app(EmailLiveInvalidator::class)->record([
            'user' => [1 => [EmailLiveProjectionChange::TYPE_PERSONAL_STATE]],
        ]);

        $this->assertDatabaseCount('email_live_projection_streams', 0);
        $this->assertDatabaseCount('email_live_projection_changes', 0);
    }

    public function test_disabled_runtime_does_not_process_preexisting_pending_changes(): void
    {
        config()->set('email_live.enabled', false);
        $user = User::factory()->create();
        $stream = EmailLiveProjectionStream::query()->create([
            'stream_type' => EmailLiveProjectionStream::TYPE_USER,
            'user_id' => $user->id,
            'current_version' => 0,
            'oldest_retained_version' => 1,
        ]);
        $stream->update(['current_version' => 1, 'last_changed_at' => now()]);
        $change = EmailLiveProjectionChange::query()->create([
            'stream_id' => $stream->id,
            'version' => 1,
            'idempotency_key' => hash('sha256', 'disabled-publisher-gate'),
            'change_types_json' => [EmailLiveProjectionChange::TYPE_PERSONAL_STATE],
            'conversation_ids_json' => null,
            'placement_ids_json' => null,
            'conversation_id_count' => 0,
            'placement_id_count' => 0,
            'publication_status' => EmailLiveProjectionChange::STATUS_PENDING,
            'available_at' => now(),
        ]);

        $publisher = app(EmailLivePublisherService::class);
        $publisher->publish($change);
        $publisher->publishPending();

        $this->assertSame(EmailLiveProjectionChange::STATUS_PENDING, $change->fresh()->publication_status);
        $this->assertNull($change->fresh()->published_at);
    }

    public function test_enabled_runtime_persists_trigger_safe_idempotent_changes(): void
    {
        config()->set('email_live.enabled', true);
        Queue::fake();
        $user = User::factory()->create();
        $invalidator = app(EmailLiveInvalidator::class);

        DB::transaction(function () use ($invalidator, $user): void {
            $batch = [
                'user' => [
                    $user->id => [
                        EmailLiveProjectionChange::TYPE_PERSONAL_STATE,
                        EmailLiveProjectionChange::TYPE_PERSONAL_STATE,
                    ],
                ],
                'conversations' => [9, 3, 9],
                'placements' => [],
                'idempotency_key' => 'open-message:'.$user->id.':9',
            ];

            $invalidator->record($batch);
            $invalidator->record($batch);
        });

        $stream = EmailLiveProjectionStream::query()->where('user_id', $user->id)->firstOrFail();
        $change = EmailLiveProjectionChange::query()->where('stream_id', $stream->id)->firstOrFail();

        $this->assertSame(1, $stream->current_version);
        $this->assertSame(64, strlen($change->idempotency_key));
        $this->assertSame([EmailLiveProjectionChange::TYPE_PERSONAL_STATE], $change->change_types_json);
        $this->assertSame([3, 9], $change->conversation_ids_json);
        $this->assertSame(2, $change->conversation_id_count);
        $this->assertNull($change->placement_ids_json);
        $this->assertSame(0, $change->placement_id_count);
        $this->assertDatabaseCount('email_live_projection_changes', 1);
        Queue::assertPushed(EmailLivePublisher::class, 1);
    }

    public function test_enabled_runtime_rejects_invalid_types_atomically(): void
    {
        config()->set('email_live.enabled', true);
        $user = User::factory()->create();

        try {
            DB::transaction(function () use ($user): void {
                app(EmailLiveInvalidator::class)->record([
                    'user' => [$user->id => ['mailbox_changed']],
                    'conversations' => [0],
                    'idempotency_key' => 'invalid-live-change',
                ]);
            });

            $this->fail('Invalid live-invalidation evidence was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Email live invalidation contains an unsupported change type.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('email_live_projection_streams', ['user_id' => $user->id]);
        $this->assertDatabaseCount('email_live_projection_changes', 0);
    }

    public function test_enabled_runtime_rejects_invalid_identifiers_atomically(): void
    {
        config()->set('email_live.enabled', true);
        $user = User::factory()->create();

        try {
            DB::transaction(function () use ($user): void {
                app(EmailLiveInvalidator::class)->record([
                    'user' => [$user->id => [EmailLiveProjectionChange::TYPE_PERSONAL_STATE]],
                    'conversations' => [0],
                    'idempotency_key' => 'invalid-live-identifier',
                ]);
            });

            $this->fail('Invalid live-invalidation identifiers were accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Email live invalidation identifiers must be positive integers.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('email_live_projection_streams', ['user_id' => $user->id]);
        $this->assertDatabaseCount('email_live_projection_changes', 0);
    }
}
