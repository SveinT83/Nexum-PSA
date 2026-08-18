<?php

namespace App\Modules\Notification\DTOs;

/** Payload-free handoff from Email rules into Notification fanout creation. */
final readonly class InboundEmailNotificationIntent
{
    public function __construct(public int $emailMessageId) {}
}
