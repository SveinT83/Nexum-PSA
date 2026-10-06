<?php

return [
    // Operational rollout gate; provider configuration alone never activates SSO.
    'enabled' => env('SSO_ENABLED', false),
    'session_minutes' => 60,
    'attempt_seconds' => 300,
];
