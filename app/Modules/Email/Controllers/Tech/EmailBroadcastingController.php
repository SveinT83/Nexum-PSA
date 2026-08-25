<?php

namespace App\Modules\Email\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Email\Services\EmailLiveRuntimeReadiness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class EmailBroadcastingController extends Controller
{
    /**
     * Authenticate the user for the private email channel.
     * We follow the Laravel Echo convention for channel authorization.
     */
    public function __invoke(Request $request, EmailLiveRuntimeReadiness $readiness)
    {
        abort_unless(
            $readiness->ready(),
            503,
            'Mail live invalidation is unavailable.',
        );

        return Broadcast::auth($request);
    }
}
