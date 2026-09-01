<?php

namespace App\Modules\Ticket\Support;

use RuntimeException;

/**
 * Safe marker for an evidence denial that callers may translate without
 * inspecting private rule definitions, targets, branches, or outcomes.
 */
final class TicketRuleRestrictedEvidence extends RuntimeException {}
