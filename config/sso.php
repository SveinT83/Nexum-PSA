<?php

return [
    // Operational rollout gate; provider configuration alone never activates SSO.
    'enabled' => env('SSO_ENABLED', false),
    // Fixed server-side grant lifetime; SsoSessions validates and caps overrides at 12 hours.
    'session_minutes' => env('SSO_SESSION_MINUTES', 720),
    'attempt_seconds' => 300,
];
