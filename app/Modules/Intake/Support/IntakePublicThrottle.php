<?php

namespace App\Modules\Intake\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** One public budget across HTML and API, independent of URL or session cookie. */
class IntakePublicThrottle
{
    public function handle(Request $request, Closure $next, string $limit = '60')
    {
        $key = 'intake:public:'.($request->isMethod('post') ? 'submit:' : 'read:').hash('sha256', (string) $request->ip());
        abort_if(RateLimiter::tooManyAttempts($key, (int) $limit), 429, 'Too many Intake requests.');
        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
