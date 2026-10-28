<?php

/** Installation boundaries are deployment-owned and cannot be changed through ordinary Admin inputs. */
return [
    'managed_allowed' => (bool) env('WEBPUSH_MANAGED_SETUP_ALLOWED', true),
    'force_disabled' => (bool) env('WEBPUSH_FORCE_DISABLED', false),
    'capability_path' => env('WEBPUSH_INSTALLATION_CAPABILITY', '/etc/nexum/webpush-managed.json'),
    'enrollment_path' => env('WEBPUSH_INSTALLATION_ENROLLMENT', '/etc/nexum/webpush-managed.enrolled'),
    'worker_ack_seconds' => 300,
];
