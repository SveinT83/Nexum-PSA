<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;

final class TicketRulePreviewService
{
    public function __construct(
        private readonly TicketRulePreviewPlanner $planner,
        private readonly TicketRuleSchema2PreviewService $schema2,
    ) {}

    /**
     * Preview delegates to the no-write planner so the public service remains
     * a small, stable boundary for the later admin preview surface.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function created(Ticket $ticket, array $context, User $operator): array
    {
        return $this->planner->created($ticket, $context, $operator);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $syntheticContext
     * @return array<string, mixed>
     */
    public function draft(
        Ticket $ticket,
        array $definition,
        User $operator,
        array $syntheticContext = [],
    ): array {
        return $this->schema2->draft(
            $ticket,
            $definition,
            $operator,
            $syntheticContext,
        );
    }
}
