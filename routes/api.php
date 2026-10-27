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

    // Sales owns its exact public quote capability and existing portal-session API branches.
    $salesPublicApiRoutes = true;
    require app_path('Modules/Sales/routes.php');
    unset($salesPublicApiRoutes);
    $commercialPublicApiRoutes = true;
    require app_path('Modules/Commercial/routes.php');
    unset($commercialPublicApiRoutes);

    $economyPortalApiRoutes = true;
    require app_path('Modules/Economy/routes.php');
    unset($economyPortalApiRoutes);

    $clientsPortalApiRoutes = true;
    require app_path('Modules/Clients/routes.php');
    unset($clientsPortalApiRoutes);

    $documentationPortalApiRoutes = true;
    require app_path('Modules/Documentation/routes.php');
    unset($documentationPortalApiRoutes);

    // ------------------------------------------------------------------------------------------
    // Protected routes
    // ------------------------------------------------------------------------------------------
    Route::middleware('auth:sanctum')->group(function () {

        // ------------------------------------------------------------------------------------------
        // Module API routes
        // ------------------------------------------------------------------------------------------
        $tdpsaLoadingApiRoutes = true;
        require app_path('Modules/Asset/routes.php');
        require app_path('Modules/UserManagement/routes.php');
        require app_path('Modules/Workday/routes.php');
        require app_path('Modules/Calendar/routes.php');
        unset($tdpsaLoadingApiRoutes);

        foreach (glob(app_path('Modules/*/api.php')) as $routeFile) {
            require $routeFile;
        }

    });
});
