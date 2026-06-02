<?php

use App\Modules\CustomField\Controllers\Api\V1\CustomFieldDefinitionController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

/*
|--------------------------------------------------------------------------
| Custom Field API routes
|--------------------------------------------------------------------------
|
| Custom field definitions are exposed read-only so integrations can discover
| configured metadata fields before writing values through domain APIs.
|
*/

Route::get('custom-fields', [CustomFieldDefinitionController::class, 'index'])
    ->name('custom-fields.index')
    ->middleware(CheckAbilities::class.':custom-fields.read');

Route::get('custom-fields/{definition}', [CustomFieldDefinitionController::class, 'show'])
    ->name('custom-fields.show')
    ->middleware(CheckAbilities::class.':custom-fields.read');
