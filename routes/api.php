<?php
// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Use Domain Architecture rout file in the module folder, Read module-architecture.md for more info.
// ---------------------------------------------------------------------------------------------------------------------------------------------------

use Illuminate\Support\Facades\Route;

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
