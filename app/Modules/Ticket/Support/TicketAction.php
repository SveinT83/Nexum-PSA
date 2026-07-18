<?php

namespace App\Modules\Ticket\Support;

class TicketAction
{
    public const ANY_TECHNICIAN_ACTIVITY = 'any_technician_activity';

    public const START_TIMER = 'start_timer';

    public const UPDATE_FIELDS = 'update_fields';

    public const CHANGE_STATUS = 'change_status';

    public const ASSIGN_OWNER = 'assign_owner';

    public const ADD_INTERNAL_NOTE = 'add_internal_note';

    public const CUSTOMER_REPLY = 'customer_reply';

    public const CUSTOMER_UPDATE = 'customer_update';

    public const REQUEST_CUSTOMER_INPUT = 'request_customer_input';

    public const SEND_SOLUTION = 'send_solution';

    public const CUSTOMER_REPLY_RECEIVED = 'customer_reply_received';

    public const CLOSE = 'close';

    public const MARK_READ = 'mark_read';

    public const APPLY_SLA = 'apply_sla';

    public const REQUEST_KNOWLEDGE_UPDATE = 'request_knowledge_update';

    public const ASSIGN_SELF = 'assign_self';

    public const ASSIGN_OTHER = 'assign_other';

    public const REGISTER_TIME = 'register_time';

    public const ADD_PLANNED_COST = 'add_planned_cost';

    public const ADD_ACTUAL_COST = 'add_actual_cost';

    public const CREATE_QUOTE = 'create_quote';

    public const EDIT_QUOTE = 'edit_quote';

    public const SEND_QUOTE = 'send_quote';

    public const MARK_QUOTE_ACCEPTANCE = 'mark_quote_acceptance';

    public const RESERVE_ITEM = 'reserve_item';

    public const PICK_ITEM = 'pick_item';

    public const REQUEST_PURCHASE = 'request_purchase';

    public const REQUEST_SENIOR_REVIEW = 'request_senior_review';

    public const SENIOR_REVIEW = 'senior_review';

    public const CLASSIFY_EVIDENCE = 'classify_evidence';

    public const ESCALATE = 'escalate';

    /**
     * @return array<string, array{label: string, type: string, write: bool, permission?: string}>
     */
    public static function definitions(): array
    {
        return [
            self::UPDATE_FIELDS => ['label' => 'Update ticket fields', 'type' => 'ticket', 'write' => true, 'permission' => 'ticket.update'],
            self::CHANGE_STATUS => ['label' => 'Change status', 'type' => 'workflow', 'write' => true, 'permission' => 'ticket.update'],
            self::ASSIGN_OWNER => ['label' => 'Assign owner', 'type' => 'assignment', 'write' => true, 'permission' => 'ticket.assign'],
            self::ASSIGN_SELF => ['label' => 'Assign to myself', 'type' => 'assignment', 'write' => true, 'permission' => 'ticket.assign'],
            self::ASSIGN_OTHER => ['label' => 'Assign another technician', 'type' => 'assignment', 'write' => true, 'permission' => 'ticket.assign'],
            self::ADD_INTERNAL_NOTE => ['label' => 'Add internal note', 'type' => 'message', 'write' => true, 'permission' => 'ticket.note_internal'],
            self::CUSTOMER_REPLY => ['label' => 'Send customer reply', 'type' => 'message', 'write' => true, 'permission' => 'ticket.reply_customer'],
            self::CUSTOMER_UPDATE => ['label' => 'Send customer update', 'type' => 'message', 'write' => true],
            self::REQUEST_CUSTOMER_INPUT => ['label' => 'Request customer input', 'type' => 'message', 'write' => true],
            self::SEND_SOLUTION => ['label' => 'Send solution', 'type' => 'message', 'write' => true],
            self::CUSTOMER_REPLY_RECEIVED => ['label' => 'Customer reply received', 'type' => 'message', 'write' => false],
            self::CLOSE => ['label' => 'Close ticket', 'type' => 'workflow', 'write' => true, 'permission' => 'ticket.close'],
            self::MARK_READ => ['label' => 'Mark read', 'type' => 'triage', 'write' => true],
            self::APPLY_SLA => ['label' => 'Apply SLA', 'type' => 'sla', 'write' => true],
            self::REQUEST_KNOWLEDGE_UPDATE => ['label' => 'Request Knowledge update', 'type' => 'documentation', 'write' => true, 'permission' => 'ticket.update'],
            self::START_TIMER => ['label' => 'Start timer', 'type' => 'time', 'write' => true, 'permission' => 'ticket.register_time'],
            self::REGISTER_TIME => ['label' => 'Register time', 'type' => 'time', 'write' => true, 'permission' => 'ticket.register_time'],
            self::ADD_PLANNED_COST => ['label' => 'Add planned cost', 'type' => 'commercial', 'write' => true, 'permission' => 'ticket.plan_cost'],
            self::ADD_ACTUAL_COST => ['label' => 'Add actual cost', 'type' => 'commercial', 'write' => true, 'permission' => 'ticket.update'],
            self::CREATE_QUOTE => ['label' => 'Create quote', 'type' => 'sales', 'write' => true, 'permission' => 'sales.quote_manage'],
            self::EDIT_QUOTE => ['label' => 'Edit quote', 'type' => 'sales', 'write' => true, 'permission' => 'sales.quote_manage'],
            self::SEND_QUOTE => ['label' => 'Send quote for approval', 'type' => 'sales', 'write' => true, 'permission' => 'sales.email_send'],
            self::MARK_QUOTE_ACCEPTANCE => ['label' => 'Mark customer quote acceptance', 'type' => 'sales', 'write' => true, 'permission' => 'ticket.approval_record'],
            self::RESERVE_ITEM => ['label' => 'Reserve approved item', 'type' => 'storage', 'write' => true, 'permission' => 'storage.reserve'],
            self::PICK_ITEM => ['label' => 'Pick approved item', 'type' => 'storage', 'write' => true, 'permission' => 'storage.pick'],
            self::REQUEST_PURCHASE => ['label' => 'Create purchase need', 'type' => 'storage', 'write' => true, 'permission' => 'storage.purchase_manage'],
            self::REQUEST_SENIOR_REVIEW => ['label' => 'Request senior review', 'type' => 'review', 'write' => true, 'permission' => 'ticket.review_request'],
            self::SENIOR_REVIEW => ['label' => 'Review as senior', 'type' => 'review', 'write' => true, 'permission' => 'ticket.review_senior'],
            self::CLASSIFY_EVIDENCE => ['label' => 'Classify customer evidence', 'type' => 'review', 'write' => true, 'permission' => 'ticket.evidence_classify'],
            self::ESCALATE => ['label' => 'Escalate ticket', 'type' => 'workflow', 'write' => true, 'permission' => 'ticket.workflow_escalate'],
        ];
    }

    /**
     * Actions that can be selected as an automatic transition trigger.
     *
     * @return array<string, array{label: string, type: string, write: bool, permission?: string}>
     */
    public static function transitionTriggerDefinitions(): array
    {
        return [
            self::ANY_TECHNICIAN_ACTIVITY => [
                'label' => 'Any technician activity',
                'type' => 'workflow',
                'write' => false,
            ],
        ] + collect(self::definitions())->only(self::technicianActivityActions())->all() + [
            self::CUSTOMER_REPLY_RECEIVED => self::definitions()[self::CUSTOMER_REPLY_RECEIVED],
        ];
    }

    /** @return array<int, string> */
    public static function technicianActivityActions(): array
    {
        return [
            self::UPDATE_FIELDS,
            self::ASSIGN_OWNER,
            self::ASSIGN_SELF,
            self::ASSIGN_OTHER,
            self::ADD_INTERNAL_NOTE,
            self::CUSTOMER_REPLY,
            self::CUSTOMER_UPDATE,
            self::REQUEST_CUSTOMER_INPUT,
            self::SEND_SOLUTION,
            self::MARK_READ,
            self::APPLY_SLA,
            self::REQUEST_KNOWLEDGE_UPDATE,
            self::START_TIMER,
            self::REGISTER_TIME,
            self::ADD_PLANNED_COST,
            self::ADD_ACTUAL_COST,
            self::CREATE_QUOTE,
            self::EDIT_QUOTE,
            self::SEND_QUOTE,
            self::MARK_QUOTE_ACCEPTANCE,
            self::RESERVE_ITEM,
            self::PICK_ITEM,
            self::REQUEST_PURCHASE,
            self::REQUEST_SENIOR_REVIEW,
            self::SENIOR_REVIEW,
            self::CLASSIFY_EVIDENCE,
        ];
    }

    public static function isTechnicianActivity(string $action): bool
    {
        return in_array($action, self::technicianActivityActions(), true);
    }
}
