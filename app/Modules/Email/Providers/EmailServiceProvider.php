<?php

namespace App\Modules\Email\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class EmailServiceProvider extends ServiceProvider
{
    /** Register Email-owned private broadcasting channels without a global auth route. */
    public function boot(): void
    {
        RateLimiter::for('email-mail-broadcast-auth', function (Request $request): Limit {
            return Limit::perMinute(30)->by(
                (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
            );
        });

        require __DIR__.'/../channels.php';
    }
}
