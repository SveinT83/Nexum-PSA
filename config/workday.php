<?php

// Keep the new employee workflow unavailable until the complete pilot is reviewed.
return [
    'enabled' => env('WORKDAY_ENABLED', false),
    // Independent operational approval: disabling employee workflows does not disable an approved retention policy.
    'retention_enabled' => env('WORKDAY_RETENTION_ENABLED', false),
];
