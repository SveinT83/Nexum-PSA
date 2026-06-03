<?php

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Use Domain Architecture route file in the module folder, Read module-architecture.md for more info.
// ---------------------------------------------------------------------------------------------------------------------------------------------------

use Illuminate\Support\Facades\Route;

// Nextcloud Talk webhook — public, no auth.
// Loaded before the auth:sanctum group so the route is accessible without authentication.
// Legitimacy is verified via HMAC-SHA256 signature headers from Nextcloud.
require app_path('Modules/Nextcloud/api.php');

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
            // Nextcloud api.php is loaded above (public webhook route) — skip it here.
            if (str_contains($routeFile, 'Modules/Nextcloud/api.php')) {
                continue;
            }
            require $routeFile;
        }

    });
});
