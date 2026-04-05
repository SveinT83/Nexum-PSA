<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Models\Core\User;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Tech\CS\Contracts\PublicContractController;

Route::get('/', function (Request $request) {
    $email = $request->query('email');
    $ts = $request->query('ts');
    $sig = $request->query('sig');

    $sharedSecret = env('NEXTCLOUD_IFRAME_SHARED_SECRET');
    $tokenValid = false;

    if ($email && $ts && $sig && $sharedSecret) {
        $maxAgeSeconds = 300;

        if (is_numeric($ts) && (time() - (int) $ts) <= $maxAgeSeconds) {
            $expectedSig = hash_hmac('sha256', $email . '|' . $ts, $sharedSecret);

            if (hash_equals($expectedSig, $sig)) {
                $tokenValid = true;
            }
        }
    }

    if ($tokenValid) {

        $user = User::where('email', $email)->first();

        if ($user) {

            Auth::login($user, true);
            $request->session()->regenerate();

            return redirect()->route('tech.dashboard');
        }
    }

    return view('welcome');
});

Route::post('/login', function (Request $request) {
    $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    if (Auth::attempt($request->only('email', 'password'), $request->boolean('remember'))) {
        $request->session()->regenerate();
        // Redirect to named route inside tech prefix group
        return redirect()->route('tech.dashboard');
    }

    throw ValidationException::withMessages([
        'email' => 'Feil e-post eller passord.',
    ]);
})->name('login');

Route::get('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/');
})->name('logout');

// ------------------------------------------------------------------------------------------
// Public Contract Routes (No Auth)
// ------------------------------------------------------------------------------------------
Route::get('/contract/view/{token}', [PublicContractController::class, 'view'])
    ->name('contracts.public.view');
Route::post('/contract/accept/{token}', [PublicContractController::class, 'accept'])
    ->name('contracts.public.accept');

// Dashboard (etter innlogging)
/*
Route::middleware('auth')->get('/dashboard', function () {
    return view('tech.dashboard');
})->name('dashboard');
*/
