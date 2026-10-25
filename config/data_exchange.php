<?php

// References only. Never store provider credentials in profiles, command payloads or API responses.
return [
    'api_delivery_enabled' => env('DATA_EXCHANGE_API_DELIVERY_ENABLED', false),
    'delivery_disks' => array_values(array_filter(explode(',', env('DATA_EXCHANGE_DELIVERY_DISKS', '')))),
    'credential_references' => array_values(array_filter(explode(',', env('DATA_EXCHANGE_CREDENTIAL_REFERENCES', '')))),
];
