<?php

use App\Modules\Notification\Models\WebPushSubscription;

return [
    /*
    |--------------------------------------------------------------------------
    | Global Web Push Readiness
    |--------------------------------------------------------------------------
    |
    | This environment-owned kill switch prevents registration and delivery
    | without changing canonical database notification behavior.
    |
    */
    'enabled' => env('WEBPUSH_ENABLED', false),

    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Persistence
    |--------------------------------------------------------------------------
    */
    'model' => WebPushSubscription::class,
    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),
    'database_connection' => env('WEBPUSH_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    */
    'client_options' => [
        'timeout' => 30,
        'connect_timeout' => 10,
    ],
    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),
];
