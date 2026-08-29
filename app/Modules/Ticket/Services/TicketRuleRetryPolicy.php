<?php

namespace App\Modules\Ticket\Services;

/**
 * Central safety bounds for immutable Ticket Rule action retry attempts.
 */
final class TicketRuleRetryPolicy
{
    private const HARD_MAX_ATTEMPTS_PER_POSITION = 20;

    private const HARD_MAX_CANDIDATE_POSITIONS = 500;

    public function maxAttemptsPerPosition(): int
    {
        return min(
            self::HARD_MAX_ATTEMPTS_PER_POSITION,
            max(1, (int) config('ticket_rules.retry.max_attempts_per_position', 3)),
        );
    }

    public function maxCandidatePositions(): int
    {
        return min(
            self::HARD_MAX_CANDIDATE_POSITIONS,
            max(1, (int) config('ticket_rules.limits.max_actions', 100)),
        );
    }
}
