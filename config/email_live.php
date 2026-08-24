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

    // Orders 9 and 12 remain independently disabled while their schemas and
    // authorization/fencing contracts are reworked.
    'collaboration_enabled' => env('EMAIL_MAIL_COLLABORATION_ENABLED', false),
    'conversation_acknowledgement_enabled' => env('EMAIL_MAIL_ACKNOWLEDGEMENT_ENABLED', false),

    // Schema attestation must fail closed instead of monopolizing deploy DDL indefinitely.
    'migration_preflight_seconds' => 30,
];
