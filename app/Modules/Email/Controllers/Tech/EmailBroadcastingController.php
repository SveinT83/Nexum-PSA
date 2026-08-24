<?php

namespace App\Modules\Email\Controllers\Tech;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class EmailBroadcastingController extends Controller
{
    /**
     * Authenticate the user for the private email channel.
     * We follow the Laravel Echo convention for channel authorization.
     */
    public function __invoke(Request $request)
    {
        abort_unless(
            config('email_live.enabled', false),
            503,
            'Mail live invalidation is unavailable.',
        );

        return Broadcast::auth($request);
    }
}
