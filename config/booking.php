<?php

return [
    // Operational activation is separate from publishing the API contract.
    'api_public_submissions_enabled' => (bool) env('BOOKING_API_PUBLIC_SUBMISSIONS_ENABLED', false),
    'api_staff_writes_enabled' => (bool) env('BOOKING_API_STAFF_WRITES_ENABLED', false),
];
