<?php

namespace App\Modules\Notification\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\NotificationSetting;
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
    public function show(): View
    {
        $user = auth()->user();
        $settings = NotificationSetting::getAllForUser($user);
        $types = NotificationSetting::TYPES;

        // Check if Nextcloud Talk is enabled system-wide
        $talkChannel = NotificationChannel::getByDriver('nextcloud_talk');
        $talkEnabled = $talkChannel?->is_enabled ?? false;

        return view('notification::settings.index', [
            'settings' => $settings,
            'types' => $types,
            'talkEnabled' => $talkEnabled,
        ]);
    }

    /**
     * Update the user's notification preferences.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = auth()->user();
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*.notification_type' => 'required|string|in:' . implode(',', array_keys(NotificationSetting::TYPES)),
            'settings.*.mail_enabled' => 'nullable|boolean',
            'settings.*.database_enabled' => 'nullable|boolean',
            'settings.*.nextcloud_talk_enabled' => 'nullable|boolean',
            'settings.*.nextcloud_talk_webhook_url' => 'nullable|url|max:500',
        ]);

        foreach ($validated['settings'] as $settingData) {
            NotificationSetting::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'notification_type' => $settingData['notification_type'],
                ],
                [
                    'mail_enabled' => $settingData['mail_enabled'] ?? false,
                    'database_enabled' => $settingData['database_enabled'] ?? false,
                    'nextcloud_talk_enabled' => $settingData['nextcloud_talk_enabled'] ?? false,
                    'nextcloud_talk_webhook_url' => $settingData['nextcloud_talk_webhook_url'] ?? null,
                ]
            );
        }

        return redirect()->route('tech.profile.notifications')
            ->with('success', 'Notification preferences updated.');
    }
}