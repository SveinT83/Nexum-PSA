<?php

use App\Modules\Integration\Controllers\Public\IntegrationHubProtectedResourceController;
use Illuminate\Support\Facades\Route;

Route::get('/.well-known/oauth-protected-resource/api/v1/integration-hub', IntegrationHubProtectedResourceController::class)
    ->name('integration-hub.oauth-protected-resource');
