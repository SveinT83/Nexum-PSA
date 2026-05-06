<?php

return [
    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | Most tdPSA domain views now live under app/Modules and are registered in
    | AppServiceProvider. The default resource path is kept for layouts,
    | components, auth screens, and legacy non-domain views.
    |
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | PHPUnit can override this with VIEW_COMPILED_PATH so tests do not depend on
    | compiled view files created by the web server user.
    |
    */

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        realpath(storage_path('framework/views')) ?: storage_path('framework/views')
    ),
];
