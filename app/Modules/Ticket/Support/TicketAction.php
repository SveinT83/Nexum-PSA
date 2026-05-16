<?php

namespace App\Modules\Ticket\Support;

class TicketAction
{
    public const UPDATE_FIELDS = 'update_fields';
    public const CHANGE_STATUS = 'change_status';
    public const ASSIGN_OWNER = 'assign_owner';
    public const ADD_INTERNAL_NOTE = 'add_internal_note';
    public const CUSTOMER_REPLY = 'customer_reply';
    public const CUSTOMER_UPDATE = 'customer_update';
    public const REQUEST_CUSTOMER_INPUT = 'request_customer_input';
    public const SEND_SOLUTION = 'send_solution';
    public const CLOSE = 'close';
    public const MARK_READ = 'mark_read';
    public const APPLY_SLA = 'apply_sla';
    public const REQUEST_KNOWLEDGE_UPDATE = 'request_knowledge_update';

    /**
     * @return array<string, array{label: string, type: string, write: bool}>
     */
    public static function definitions(): array
    {
        return [
            self::UPDATE_FIELDS => ['label' => 'Update ticket fields', 'type' => 'ticket', 'write' => true],
            self::CHANGE_STATUS => ['label' => 'Change status', 'type' => 'workflow', 'write' => true],
            self::ASSIGN_OWNER => ['label' => 'Assign owner', 'type' => 'assignment', 'write' => true],
            self::ADD_INTERNAL_NOTE => ['label' => 'Add internal note', 'type' => 'message', 'write' => true],
            self::CUSTOMER_REPLY => ['label' => 'Send customer reply', 'type' => 'message', 'write' => true],
            self::CUSTOMER_UPDATE => ['label' => 'Send customer update', 'type' => 'message', 'write' => true],
            self::REQUEST_CUSTOMER_INPUT => ['label' => 'Request customer input', 'type' => 'message', 'write' => true],
            self::SEND_SOLUTION => ['label' => 'Send solution', 'type' => 'message', 'write' => true],
            self::CLOSE => ['label' => 'Close ticket', 'type' => 'workflow', 'write' => true],
            self::MARK_READ => ['label' => 'Mark read', 'type' => 'triage', 'write' => true],
            self::APPLY_SLA => ['label' => 'Apply SLA', 'type' => 'sla', 'write' => true],
            self::REQUEST_KNOWLEDGE_UPDATE => ['label' => 'Request Knowledge update', 'type' => 'documentation', 'write' => true],
        ];
    }
}
