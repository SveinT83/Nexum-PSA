<?php

return [
    // API exposure cannot activate real provider customer creation without an operator decision.
    'api_provider_customer_creation_enabled' => env('INTAKE_API_PROVIDER_CUSTOMER_CREATION_ENABLED', false),
];
