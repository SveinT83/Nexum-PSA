<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Durable Mail invalidation bounds
    |--------------------------------------------------------------------------
    |
    | These values are hard safety ceilings, not tuning targets. The runtime
    | remains unavailable until a real private hint transport is registered.
    |
    */
    'queue' => 'email-live',
    'candidate_page_size' => 100,
    'access_recompute_page_size' => 100,
    'publisher_page_size' => 100,
    'catch_up_version_limit' => 250,
    'current_view_row_limit' => 25,
    'identifier_limit' => 50,
    'max_attempts' => 3,
    'abandoned_claim_seconds' => 90,
    'retry_delay_seconds' => 15,
    'connected_safety_seconds' => 120,
    'retention_hours' => 72,
    'retention_changes_per_stream' => 10_000,

    // Order 8 is runtime-gated until fanout, authorization and fallback are verified.
    'enabled' => env('EMAIL_LIVE_ENABLED', false),

    // A second explicit operations gate prevents an accidental environment
    // toggle from opening sockets before the supervised rollout is approved.
    'runtime_approved' => env('EMAIL_LIVE_RUNTIME_APPROVED', false),

    // Exact browser origins only; an empty list rejects every Reverb origin.
    'allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => trim($origin),
        explode(',', (string) env('REVERB_ALLOWED_ORIGINS', '')),
    ))),

    // Orders 9 and 12 remain independently disabled until their schema,
    // authorization, operational transport and named human-review gates pass.
    'collaboration_enabled' => env('EMAIL_MAIL_COLLABORATION_ENABLED', false),
    'collaboration_ui_enabled' => env('EMAIL_MAIL_COLLABORATION_UI_ENABLED', false),
    'presence_store' => env('EMAIL_MAIL_PRESENCE_STORE', 'redis'),
    'presence_reading_ttl_seconds' => 45,
    'presence_typing_ttl_seconds' => 25,
    'presence_heartbeat_floor_seconds' => 10,
    'shared_draft_lease_seconds' => 60,
    'shared_draft_renew_floor_seconds' => 20,
    'conversation_acknowledgement_enabled' => env('EMAIL_MAIL_ACKNOWLEDGEMENT_ENABLED', false),

    // Conversation acknowledgement is bounded even after its separate rollout gate is enabled.
    'conversation_acknowledgement_preview_cap' => 100,
    'conversation_acknowledgement_hard_cap' => 500,
    'conversation_acknowledgement_max_accounts' => 20,
    'conversation_acknowledgement_preview_ttl_minutes' => 15,

    // Schema attestation must fail closed instead of monopolizing deploy DDL indefinitely.
    'migration_preflight_seconds' => 30,
];
