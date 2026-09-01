<?php

use App\Modules\Integration\Support\EmailProviderTrustedPrivateCidrConfiguration;

return [
    // Provider-first staging and migration are retired. This switch exists
    // only so the historical lifecycle tests can remain executable.
    'legacy_lifecycle_enabled' => (bool) env('EMAIL_PROVIDER_LEGACY_LIFECYCLE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Email provider endpoint security
    |--------------------------------------------------------------------------
    |
    | Standard IMAP/SMTP ports are fixed in code. Non-standard ports and
    | private networks are installation decisions and default to unavailable.
    | Values are deliberately parsed by the Integration service instead of
    | being accepted directly from an Email account form.
    |
    */
    'additional_endpoints' => [],

    'trusted_private_cidrs' => EmailProviderTrustedPrivateCidrConfiguration::exactRfc1918Ipv4Host(
        'tronderdata_mail_dev',
        env('EMAIL_PROVIDER_TRONDERDATA_MAIL_DEV_CIDR'),
    ),

    // Installation-reviewed mapping: email_account_id => named CIDR.
    'legacy_trusted_private_accounts' => [],

    'dns' => [
        'max_answers' => 16,
        'max_cname_depth' => 8,
    ],

    'connection_timeout_seconds' => 20,
    // One explicit Verify action must finish before its durable lease can be
    // reclaimed. Per-socket timeouts are additionally clamped to this budget.
    'verification_deadline_seconds' => 60,
    'verification_cleanup_grace_seconds' => 2,
    'verification_outer_alarm_margin_seconds' => 10,
    'verification_lease_seconds' => 120,
    'minimum_tls_version' => '1.2',
];
