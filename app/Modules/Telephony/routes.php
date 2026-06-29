<?php

use App\Modules\Telephony\Controllers\Public\TelephonyIntakeController;
use App\Modules\Telephony\Controllers\Tech\TelephonyProfileController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

if (($telephonyPublicRoutes ?? false) === true) {
    Route::match(['get', 'post'], '/telephony/intake/{token}', [TelephonyIntakeController::class, 'show'])
        ->middleware('throttle:30,1')
        ->withoutMiddleware([ValidateCsrfToken::class])
        ->name('telephony.intake');

    Route::get('/telephony/intake/{token}/calls/{call}', [TelephonyIntakeController::class, 'call'])
        ->middleware('throttle:60,1')
        ->name('telephony.intake.call');

    Route::post('/telephony/intake/{token}/calls/{call}/note', [TelephonyIntakeController::class, 'updateNote'])
        ->middleware('throttle:30,1')
        ->name('telephony.intake.calls.note');

    Route::post('/telephony/intake/{token}/calls/{call}/ticket', [TelephonyIntakeController::class, 'createTicket'])
        ->middleware('throttle:20,1')
        ->name('telephony.intake.calls.ticket');

    Route::post('/telephony/intake/{token}/calls/{call}/link-ticket', [TelephonyIntakeController::class, 'linkTicket'])
        ->middleware('throttle:30,1')
        ->name('telephony.intake.calls.link-ticket');

    return;
}

Route::get('/telephony/profile', [TelephonyProfileController::class, 'show'])
    ->name('telephony.profile');

Route::post('/telephony/profile/token/rotate', [TelephonyProfileController::class, 'rotate'])
    ->name('telephony.profile.token.rotate');
