<?php

namespace App\Modules\Notification\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Support\NotificationTypeRegistry;
use App\Modules\Notification\Support\WebPushReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * User-facing notification preferences controller.
 *
 * Lets each user configure which channels they want for each
 * notification type (email, in-app, Nextcloud Talk).
 */
class NotificationSettingsController extends Controller
{
    /**
     * Show the user's notification preferences.
     */
    public function show(WebPushReadiness $webPushReadiness): View
    {
        $user = auth()->user();
        $types = NotificationTypeRegistry::labels(NotificationTypeRegistry::AUDIENCE_INTERNAL);
        $workdayAllowed = app(\App\Modules\Workday\Support\ReminderEligibility::class)->allowed($user);
        if (! $workdayAllowed) {
            unset($types['workday_reminder']);
        }
        $groups = NotificationTypeRegistry::groupedInternal();
        if (! $workdayAllowed) {
            unset($groups['workday']);
        }
        $workdayReadiness = $workdayAllowed
            ? app(\App\Modules\Notification\Actions\WorkdayReminderPreferences::class)->readiness($user) : [];
        $settings = NotificationSetting::getAllForUser($user, array_keys($types));

        // Check if Nextcloud Talk is enabled system-wide
        $talkChannel = NotificationChannel::getByDriver('nextcloud_talk');
        $talkEnabled = $talkChannel?->is_enabled ?? false;

        return view('notification::settings.index', [
            'settings' => $settings,
            'types' => $types,
            'groups' => $groups,
            'workdayReadiness' => $workdayReadiness,
            'talkEnabled' => $talkEnabled,
            'webPushReadiness' => $webPushReadiness->toArray(),
        ]);
    }

    /**
     * Update the user's notification preferences.
     */
    public function update(Request $request): RedirectResponse
    {
        app(\App\Modules\Notification\Actions\ManageNotificationPreferences::class)->update($request);

        return redirect()->route('tech.profile.notifications')
            ->with('success', 'Notification preferences updated.');
    }
}
