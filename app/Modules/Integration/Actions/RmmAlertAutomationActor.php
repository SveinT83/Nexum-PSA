<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Modules\UserManagement\Actions\EnsureSystemActor;

class RmmAlertAutomationActor
{
    public const KEY = 'rmm_alert_rule_automation';

    public function __construct(private readonly EnsureSystemActor $systemActors) {}

    public function resolve(): User
    {
        return $this->systemActors->handle(
            key: self::KEY,
            name: 'Nexum RMM Alert Rules',
            email: 'rmm-alert-rules@system.invalid',
            permissions: [
                'ticket.create',
                'ticket.note_internal',
                'ticket.reopen',
                'task.create',
                'task.source_update',
            ],
        );
    }
}
