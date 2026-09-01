<?php

namespace App\Modules\Ticket\Support;

use App\Modules\Ticket\Models\TicketRuleRun;

final readonly class TicketRuleExecutionResult
{
    /**
     * @param  list<array{delivery_id: int, payload: array<string, mixed>}>  $deliveries
     * @param  array<string, int>  $counters
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public TicketRuleRun $run,
        public string $terminalStatus,
        public array $deliveries,
        public array $counters,
        public array $summary,
    ) {}
}
