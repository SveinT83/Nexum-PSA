<?php
// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Use Domain Architecture rout file in the module folder, Read module-architecture.md for more info.
// ---------------------------------------------------------------------------------------------------------------------------------------------------

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Models\Core\User;
use App\Modules\CustomerPortal\Support\CustomerPortalContextResolver;
use Illuminate\Support\Facades\Log;

Route::get('/', function (Request $request) {
    if (Auth::check()) {
        $user = $request->user();

        if ($user?->isActive() && ($user->roles()->exists() || $user->permissions()->exists())) {
            return redirect()->route('tech.dashboard');
        }

        if ($user?->isActive() && app(CustomerPortalContextResolver::class)->resolveForUser($user)) {
            return redirect()->route('customer-portal.dashboard');
        }
    }

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

/*
 * Fjernet for å ikek skape konflikt med Laravel Fortify
 */

/*
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
*/

Route::get('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/');
})->name('legacy.logout');

$commercialPublicRoutes = true;
require app_path('Modules/Commercial/routes.php');

$salesPublicRoutes = true;
require app_path('Modules/Sales/routes.php');

$marketingPublicRoutes = true;
require app_path('Modules/Marketing/routes.php');

$userManagementPublicRoutes = true;
require app_path('Modules/UserManagement/routes.php');

$telephonyPublicRoutes = true;
require app_path('Modules/Telephony/routes.php');

$intakePublicRoutes = true;
require app_path('Modules/Intake/routes.php');

$bookingPublicRoutes = true;
require app_path('Modules/Booking/routes.php');

$customerPortalPublicRoutes = true;
require app_path('Modules/CustomerPortal/routes.php');

$salesPortalRoutes = true;
require app_path('Modules/Sales/routes.php');

$ticketPortalRoutes = true;
require app_path('Modules/Ticket/routes.php');

$documentationPortalRoutes = true;
require app_path('Modules/Documentation/routes.php');

$knowledgePortalRoutes = true;
require app_path('Modules/Knowledge/routes.php');

$commercialPortalRoutes = true;
require app_path('Modules/Commercial/routes.php');

$economyPortalRoutes = true;
require app_path('Modules/Economy/routes.php');

unset(
    $commercialPublicRoutes,
    $salesPublicRoutes,
    $marketingPublicRoutes,
    $userManagementPublicRoutes,
    $telephonyPublicRoutes,
    $intakePublicRoutes,
    $bookingPublicRoutes,
    $customerPortalPublicRoutes,
    $salesPortalRoutes,
    $ticketPortalRoutes,
    $documentationPortalRoutes,
    $knowledgePortalRoutes,
    $commercialPortalRoutes,
    $economyPortalRoutes,
);

// Dashboard (etter innlogging)
/*
Route::middleware('auth')->get('/dashboard', function () {
    return view('tech.dashboard');
})->name('dashboard');
*/
