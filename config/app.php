<?php

/*
|--------------------------------------------------------------------------
| Automatic Build Metadata
|--------------------------------------------------------------------------
|
| Release Please owns version.txt. Composer install records the exact root-
| package source reference during a normal deployment. Environment values remain
| optional overrides for packaged builds that do not include those sources.
|
*/

$versionFile = dirname(__DIR__).DIRECTORY_SEPARATOR.'version.txt';
$sourceVersion = is_readable($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
$sourceVersion = $sourceVersion !== '' ? $sourceVersion : '0.1.0';
$composerRoot = class_exists(\Composer\InstalledVersions::class)
    ? \Composer\InstalledVersions::getRootPackage()
    : [];
$composerReference = is_string($composerRoot['reference'] ?? null)
    ? $composerRoot['reference']
    : null;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Nexum PSA'),

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | This value is the current version of Nexum PSA. Release Please updates
    | version.txt, while APP_VERSION remains an optional deployment override.
    | Composer's root reference identifies the exact deployed build.
    |
    */

    'version' => env('APP_VERSION', $sourceVersion),

    'commit' => env('APP_COMMIT_SHA', $composerReference),

    'update' => [
        'branch' => env('APP_UPDATE_BRANCH', 'main'),
        'github_repository' => env('APP_GITHUB_REPOSITORY', 'SveinT83/Nexum-PSA'),
        'github_token' => env('APP_GITHUB_TOKEN'),
        'include_prereleases' => env('APP_UPDATE_PRERELEASES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
