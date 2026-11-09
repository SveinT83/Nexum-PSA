<?php

// External API communication is enabled explicitly after deployment/human review.
return [
    // Expose only after all ledger-aware writers and native race checks are verified.
    'timebank_corrections_enabled' => env('COMMERCIAL_TIMEBANK_CORRECTIONS_ENABLED', false),
    'api_delivery_enabled' => env('COMMERCIAL_API_DELIVERY_ENABLED', false),
    'durable_delivery_enabled' => env('COMMERCIAL_DURABLE_DELIVERY_ENABLED', false),
];
