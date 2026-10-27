<?php

namespace App\Modules\Notification\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Actions\ManageNotificationPreferences;
use Illuminate\Http\Request;

/** Typed discovery and own-only preference adapter. */
class NotificationPreferencesController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(['data' => app(ManageNotificationPreferences::class)->context(
            $request->user(), (bool) ($request->route()->defaults['notification_portal'] ?? false))]);
    }

    public function update(Request $request)
    {
        return response()->json(['data' => app(ManageNotificationPreferences::class)->update(
            $request, (bool) ($request->route()->defaults['notification_portal'] ?? false))]);
    }
}
