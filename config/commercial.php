<?php

// External API communication is enabled explicitly after deployment/human review.
return [
    'api_delivery_enabled' => env('COMMERCIAL_API_DELIVERY_ENABLED', false),
    'durable_delivery_enabled' => env('COMMERCIAL_DURABLE_DELIVERY_ENABLED', false),
];
