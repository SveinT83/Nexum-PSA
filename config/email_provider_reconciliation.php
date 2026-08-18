<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Optional IMAP IDLE latency hints
    |--------------------------------------------------------------------------
    |
    | Scheduled full reconciliation is the correctness path. Enable this only
    | when a supervised `email-idle` queue worker is installed; otherwise no
    | IDLE jobs are produced and the default Email worker has no extra backlog.
    |
    */
    'idle_enabled' => (bool) env('EMAIL_PROVIDER_IDLE_ENABLED', false),

    // DB-only placement inventories still advance in resumable pages. This
    // may be lowered for constrained workers but is hard-clamped to 500.
    'placement_snapshot_batch_size' => 500,

    // Account-local folder history is traversed by durable primary-key pages
    // and cannot be widened beyond the service hard cap of 100 rows/job.
    'local_folder_snapshot_batch_size' => 100,
];
