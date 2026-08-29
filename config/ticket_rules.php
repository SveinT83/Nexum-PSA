<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ticket Rule v2 runtime capability
    |--------------------------------------------------------------------------
    |
    | The database authority fence and this capability must both select v2.
    | Keep the capability disabled until compatibility and human review pass.
    |
    */
    'v2_enabled' => (bool) env('TICKET_RULE_V2_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Separately reviewed full-rerun boundary
    |--------------------------------------------------------------------------
    |
    | This remains off independently of v2 runtime authority. Enabling it only
    | exposes the signed-preview boundary for terminal ticket.created runs.
    |
    */

    'full_rerun_enabled' => (bool) env('TICKET_RULE_FULL_RERUN_ENABLED', false),

    /*
    | SQLite has no authoritative per-Ticket row lock. This switch exists only
    | for isolated savepoint tests and is ignored outside the testing environment.
    */
    'allow_sqlite_mutations_for_tests' => false,

    /*
    |--------------------------------------------------------------------------
    | Slice 3 typed trigger and action capabilities
    |--------------------------------------------------------------------------
    |
    | These switches are independent of the v2 authority fence. Every schema 2
    | capability stays disabled until its migration, permission, and Dev
    | verification gates have passed. Immutable schema 1 compatibility
    | versions do not consult these switches.
    |
    */
    'capabilities' => [
        'triggers' => [
            'ticket.created' => false,
            'ticket.updated' => false,
            'ticket.field_changed' => false,
            'ticket.message_added' => false,
            'ticket.tags_changed' => false,
            'ticket.assignment_changed' => false,
            'ticket.custom_fields_changed' => false,
            'ticket.workflow_changed' => false,
            'ticket.workflow_state_changed' => false,
            'ticket.status_changed' => false,
        ],
        'actions' => [
            'set_ticket_fields' => false,
            'set_queue' => false,
            'assign_owner' => false,
            'unassign_owner' => false,
            'rerun_assignment' => false,
            'add_tags' => false,
            'remove_tags' => false,
            'add_internal_note' => false,
            'set_custom_field' => false,
            'clear_custom_field' => false,
            'select_workflow' => false,
            'transition_workflow' => false,
            'switch_workflow' => false,
            'pause_workflow_automation' => false,
            'resume_workflow_automation' => false,
            'emit_signal' => false,
        ],
        'custom_fields' => [
            'ui_write' => false,
            'api_write' => false,
            'rule_trigger' => false,
            'rule_action' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Controlled action retry
    |--------------------------------------------------------------------------
    |
    | This is the total immutable attempt cap for one action position,
    | including its original runtime attempt. Execution detail renders only
    | this many newest attempts per position and reports historical omissions.
    |
    */
    'retry' => [
        'max_attempts_per_position' => (int) env('TICKET_RULE_MAX_RETRY_ATTEMPTS_PER_POSITION', 3),
    ],

    'limits' => [
        'max_depth' => (int) env('TICKET_RULE_MAX_DEPTH', 8),
        'max_evaluated_rules' => (int) env('TICKET_RULE_MAX_EVALUATED_RULES', 100),
        'max_actions' => (int) env('TICKET_RULE_MAX_ACTIONS', 100),
    ],
];
