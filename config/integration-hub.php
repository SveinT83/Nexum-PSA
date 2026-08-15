<?php

return [
    'installation_key' => env('INTEGRATION_HUB_INSTALLATION_KEY', 'installation'),
    'issuer' => env('INTEGRATION_HUB_ISSUER', env('APP_URL').'/api/v1/integration-hub'),
    'audience' => env('INTEGRATION_HUB_AUDIENCE', env('APP_URL').'/mcp'),
    'authorization_server' => env('INTEGRATION_HUB_AUTHORIZATION_SERVER', env('APP_URL')),
    'service_actor_key' => env('INTEGRATION_HUB_SERVICE_ACTOR_KEY', 'integration_hub_mcp'),
    'grant_ttl_seconds' => (int) env('INTEGRATION_HUB_GRANT_TTL_SECONDS', 300),
    'clock_skew_seconds' => (int) env('INTEGRATION_HUB_CLOCK_SKEW_SECONDS', 30),
    'active_grant_key_id' => env('INTEGRATION_HUB_GRANT_KEY_ID', 'v1'),
    'active_grant_key' => env('INTEGRATION_HUB_GRANT_KEY'),
    'previous_grant_key_id' => env('INTEGRATION_HUB_PREVIOUS_GRANT_KEY_ID'),
    'previous_grant_key' => env('INTEGRATION_HUB_PREVIOUS_GRANT_KEY'),
    'max_page_size' => (int) env('INTEGRATION_HUB_MAX_PAGE_SIZE', 50),
    'default_stale_after_seconds' => (int) env('INTEGRATION_HUB_STALE_AFTER_SECONDS', 900),
    'audit_retention_days' => (int) env('INTEGRATION_HUB_AUDIT_RETENTION_DAYS', 90),
    'plesk' => [
        'connect_timeout_seconds' => (int) env('INTEGRATION_HUB_PLESK_CONNECT_TIMEOUT', 3),
        'timeout_seconds' => (int) env('INTEGRATION_HUB_PLESK_TIMEOUT', 10),
        'max_response_bytes' => (int) env('INTEGRATION_HUB_PLESK_MAX_RESPONSE_BYTES', 1048576),
        'retry_delay_min_ms' => (int) env('INTEGRATION_HUB_PLESK_RETRY_MIN_MS', 50),
        'retry_delay_max_ms' => (int) env('INTEGRATION_HUB_PLESK_RETRY_MAX_MS', 150),
        'tls_timeout_seconds' => (int) env('INTEGRATION_HUB_TLS_TIMEOUT', 5),
    ],
];
