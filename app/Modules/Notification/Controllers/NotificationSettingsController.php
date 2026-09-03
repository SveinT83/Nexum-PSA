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
        $settings = NotificationSetting::getAllForUser($user, array_keys($types));

        // Check if Nextcloud Talk is enabled system-wide
        $talkChannel = NotificationChannel::getByDriver('nextcloud_talk');
        $talkEnabled = $talkChannel?->is_enabled ?? false;

        return view('notification::settings.index', [
            'settings' => $settings,
            'types' => $types,
            'groups' => NotificationTypeRegistry::groupedInternal(),
            'talkEnabled' => $talkEnabled,
            'webPushReadiness' => $webPushReadiness->toArray(),
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
            'settings.*.notification_type' => 'required|string|distinct|in:'.implode(',', array_keys(NotificationTypeRegistry::labels(NotificationTypeRegistry::AUDIENCE_INTERNAL))),
            'settings.*.mail_enabled' => 'nullable|boolean',
            'settings.*.database_enabled' => 'nullable|boolean',
            'settings.*.web_push_enabled' => 'nullable|boolean',
            'settings.*.web_push_preview_enabled' => 'nullable|boolean',
            'settings.*.nextcloud_talk_enabled' => 'nullable|boolean',
            'settings.*.nextcloud_talk_webhook_url' => 'nullable|url|max:500',
        ]);

        foreach ($validated['settings'] as $settingData) {
            $type = $settingData['notification_type'];
            $mailEnabled = NotificationTypeRegistry::supports($type, 'mail')
                && (bool) ($settingData['mail_enabled'] ?? false);
            $databaseEnabled = NotificationTypeRegistry::supports($type, 'database')
                && (bool) ($settingData['database_enabled'] ?? false);
            $webPushEnabled = NotificationSetting::supportsWebPush($type)
                && (bool) ($settingData['web_push_enabled'] ?? false);
            $webPushPreviewEnabled = NotificationSetting::supportsWebPushPreview($type)
                && (bool) ($settingData['web_push_preview_enabled'] ?? false);
            $talkEnabled = NotificationTypeRegistry::supports($type, 'nextcloud_talk')
                && (bool) ($settingData['nextcloud_talk_enabled'] ?? false);

            NotificationSetting::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'notification_type' => $type,
                ],
                [
                    'mail_enabled' => $mailEnabled,
                    'database_enabled' => $databaseEnabled,
                    'web_push_enabled' => $webPushEnabled,
                    'web_push_preview_enabled' => $webPushPreviewEnabled,
                    'nextcloud_talk_enabled' => $talkEnabled,
                    'nextcloud_talk_webhook_url' => $talkEnabled
                        ? ($settingData['nextcloud_talk_webhook_url'] ?? null)
                        : null,
                ]
            );
        }

        return redirect()->route('tech.profile.notifications')
            ->with('success', 'Notification preferences updated.');
    }
}
