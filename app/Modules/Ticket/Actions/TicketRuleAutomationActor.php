<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\UserManagement\Actions\EnsureSystemActor;

/**
 * Resolve the fixed least-privilege identity for unattended Ticket Rule actions.
 */
class TicketRuleAutomationActor
{
    public const KEY = 'ticket_rule_automation';

    public const PERMISSIONS = [
        'ticket.update',
        'ticket.assign',
        'ticket.note_internal',
        'ticket.workflow_escalate',
        'signal.action.execute',
    ];

    public function __construct(private readonly EnsureSystemActor $ensureSystemActor) {}

    public function resolve(): User
    {
        return $this->ensureSystemActor->handle(
            key: self::KEY,
            name: 'Nexum Ticket Rule Automation',
            email: 'ticket-rule-automation@system.nexum.invalid',
            permissions: self::PERMISSIONS,
        );
    }
}
