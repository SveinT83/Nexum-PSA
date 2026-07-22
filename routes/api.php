<?php

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Use Domain Architecture route file in the module folder, Read module-architecture.md for more info.
// ---------------------------------------------------------------------------------------------------------------------------------------------------

use Illuminate\Support\Facades\Route;

// Nextcloud Talk webhook — public, no auth.
// Loaded before the auth:sanctum group so the route is accessible without authentication.
// Legitimacy is verified via HMAC-SHA256 signature headers from Nextcloud.
$tdpsaLoadingPublicApiRoutes = true;
require app_path('Modules/Nextcloud/api.php');
unset($tdpsaLoadingPublicApiRoutes);

require app_path('Modules/Relationship/api-public.php');

// Cloud Factory notifications use a provider-managed shared header key.
$tdpsaLoadingCloudFactoryPublicRoutes = true;
require app_path('Modules/Integration/routes.php');
unset($tdpsaLoadingCloudFactoryPublicRoutes);

Route::prefix('v1')->name('api.v1.')->group(function () {

    // ------------------------------------------------------------------------------------------
    // Protected routes
    // ------------------------------------------------------------------------------------------
    Route::middleware('auth:sanctum')->group(function () {

        // ------------------------------------------------------------------------------------------
        // Module API routes
        // ------------------------------------------------------------------------------------------
        $tdpsaLoadingApiRoutes = true;
        require app_path('Modules/Asset/routes.php');
        unset($tdpsaLoadingApiRoutes);

        foreach (glob(app_path('Modules/*/api.php')) as $routeFile) {
            require $routeFile;
        }

    });
});
