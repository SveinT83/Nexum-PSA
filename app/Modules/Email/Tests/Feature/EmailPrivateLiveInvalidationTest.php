<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Events\EmailProjectionInvalidated;
use App\Modules\Email\Models\EmailLiveProjectionChange;
use App\Modules\Email\Models\EmailLiveProjectionStream;
use App\Modules\Email\Services\EmailLiveInvalidator;
use App\Modules\Email\Services\EmailLivePublisherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailPrivateLiveInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_invalidation_batch_within_transaction()
    {
        $user = User::factory()->create();
        $invalidator = new EmailLiveInvalidator;
        config()->set('email_live.enabled', true);
        Queue::fake();

        DB::transaction(function () use ($invalidator, $user) {
            $invalidator->record([
                'user' => [
                    $user->id => [EmailLiveProjectionChange::TYPE_MAIL_PROJECTION],
                ],
                'conversations' => [101, 102],
                'idempotency_key' => 'private-live-invalidation-test',
            ]);
        });

        $stream = EmailLiveProjectionStream::where('user_id', $user->id)->first();
        $this->assertNotNull($stream);
        $this->assertEquals(1, $stream->current_version);

        $change = EmailLiveProjectionChange::where('stream_id', $stream->id)->first();
        $this->assertNotNull($change);
        $this->assertEquals(1, $change->version);
        $this->assertContains(EmailLiveProjectionChange::TYPE_MAIL_PROJECTION, $change->change_types_json);
        $this->assertContains(101, $change->conversation_ids_json);
        $this->assertContains(102, $change->conversation_ids_json);
    }

    public function test_it_broadcasts_invalidation_event_on_publication()
    {
        Event::fake([EmailProjectionInvalidated::class]);

        $user = User::factory()->create();

        $stream = EmailLiveProjectionStream::create([
            'stream_type' => EmailLiveProjectionStream::TYPE_USER,
            'user_id' => $user->id,
            'current_version' => 0,
            'oldest_retained_version' => 1,
        ]);
        $stream->update([
            'current_version' => 1,
            'last_changed_at' => now(),
        ]);

        $change = EmailLiveProjectionChange::create([
            'stream_id' => $stream->id,
            'version' => 1,
            'idempotency_key' => hash('sha256', 'private-live-broadcast-test'),
            'change_types_json' => [EmailLiveProjectionChange::TYPE_MAIL_PROJECTION],
            'conversation_ids_json' => null,
            'placement_ids_json' => null,
            'conversation_id_count' => 0,
            'placement_id_count' => 0,
            'publication_status' => EmailLiveProjectionChange::STATUS_PENDING,
            'available_at' => now(),
        ]);

        $service = app(EmailLivePublisherService::class);

        // We need to bypass the publication/delivery phase logic which is complex
        // and call broadcast directly to verify it works.
        // In a real scenario, processDelivery calls broadcast.

        $method = new \ReflectionMethod(EmailLivePublisherService::class, 'broadcast');
        $method->setAccessible(true);
        $method->invoke($service, $user->id, $change);

        $event = new EmailProjectionInvalidated($user->id, [
            'schema' => 1,
            'scope' => 'user',
            'from_version' => '0',
            'to_version' => '1',
            'change_types' => [EmailLiveProjectionChange::TYPE_MAIL_PROJECTION],
            'conversation_ids' => [],
            'placement_ids' => [],
            'truncated' => false,
        ]);

        $this->assertSame($event->payload, $event->broadcastWith());
        $this->assertArrayNotHasKey('userId', $event->broadcastWith());

        Event::assertDispatched(
            EmailProjectionInvalidated::class,
            fn (EmailProjectionInvalidated $event): bool => $event->payload['scope'] === 'user'
                && $event->payload['to_version'] === '1'
                && $event->payload['conversation_ids'] === []
                && $event->payload['placement_ids'] === []
                && in_array(
                    EmailLiveProjectionChange::TYPE_MAIL_PROJECTION,
                    $event->payload['change_types'],
                    true,
                ),
        );
    }
}
