<?php

namespace App\Modules\Notification\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Actions\OpenNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/** Browser adapter over the same authoritative action as API and bell. */
class NotificationOpenController extends Controller
{
    public function __invoke(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        return redirect()->to(app(OpenNotification::class)->handle($request->user(), $notification->id)['target_url']);
    }
}
