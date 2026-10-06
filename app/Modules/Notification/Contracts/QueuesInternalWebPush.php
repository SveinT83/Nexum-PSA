<?php

namespace App\Modules\Notification\Contracts;

/**
 * Supplies only privacy-safe data for the generic queued Web Push boundary.
 */
interface QueuesInternalWebPush
{
    public function internalWebPushType(): string;

    /**
     * @return array{title:string,body:string,target_id:int|string|null,ttl:int,urgency:string}
     */
    public function internalWebPushPayload(): array;
}
