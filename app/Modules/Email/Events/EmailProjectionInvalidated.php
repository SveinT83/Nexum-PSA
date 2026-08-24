<?php

namespace App\Modules\Email\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmailProjectionInvalidated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param array{
     *   schema: int,
     *   scope: string,
     *   from_version: string,
     *   to_version: string,
     *   change_types: array<string>,
     *   conversation_ids: array<int>,
     *   placement_ids: array<int>,
     *   truncated: bool
     * } $payload
     */
    public function __construct(
        public int $userId,
        public array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("email.user.{$this->userId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'email.projection.invalidated.v1';
    }

    /** Expose only the approved opaque invalidation manifest to the browser. */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
