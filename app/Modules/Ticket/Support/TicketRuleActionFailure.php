<?php

namespace App\Modules\Ticket\Support;

use RuntimeException;

final class TicketRuleActionFailure extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $safeMessage,
    ) {
        parent::__construct($safeMessage);
    }
}
