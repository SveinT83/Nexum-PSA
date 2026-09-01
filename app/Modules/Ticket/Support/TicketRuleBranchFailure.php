<?php

namespace App\Modules\Ticket\Support;

use RuntimeException;
use Throwable;

final class TicketRuleBranchFailure extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $completedResults
     */
    public function __construct(
        public readonly array $completedResults,
        public readonly int $failedPosition,
        public readonly string $reasonCode,
        public readonly string $failedPreconditionFingerprint,
        string $safeMessage,
        ?Throwable $previous = null,
    ) {
        parent::__construct($safeMessage, 0, $previous);
    }
}
