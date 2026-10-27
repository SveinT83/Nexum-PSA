<?php

return [
    // Installing contracts never releases historical jobs or activates operational changes.
    'maintenance_enabled' => env('SYSTEM_API_MAINTENANCE_ENABLED', false),
    'confirmation_seconds' => 120,
];
