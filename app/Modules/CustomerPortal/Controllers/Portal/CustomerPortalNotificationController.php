<?php

namespace App\Modules\CustomerPortal\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Notifications\CustomerPortalNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

class CustomerPortalNotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()
            ->notifications()
            ->where('type', CustomerPortalNotification::class)
            ->latest()
            ->paginate(20);

        return view('customerportal::Portal.notifications.index', [
            'context' => $this->context($request),
            'notifications' => $notifications,
            'unreadCount' => $request->user()
                ->unreadNotifications()
                ->where('type', CustomerPortalNotification::class)
                ->count(),
            'types' => NotificationSetting::CUSTOMER_PORTAL_TYPES,
            'settings' => NotificationSetting::getAllForUser($request->user())
                ->only(array_keys(NotificationSetting::CUSTOMER_PORTAL_TYPES)),
        ]);
    }

    public function open(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $this->authorizeNotification($request, $notification);

        $notification->markAsRead();

        return redirect()->to($this->safeRedirectUrl($notification->data['url'] ?? null));
    }

    public function markRead(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $this->authorizeNotification($request, $notification);

        $notification->markAsRead();

        return back()->with('success', 'Notification marked as read.');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()
            ->unreadNotifications()
            ->where('type', CustomerPortalNotification::class)
            ->update(['read_at' => now()]);

        return back()->with('success', 'All portal notifications were marked as read.');
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.notification_type' => ['required', 'string', 'in:'.implode(',', array_keys(NotificationSetting::CUSTOMER_PORTAL_TYPES))],
            'settings.*.mail_enabled' => ['nullable', 'boolean'],
            'settings.*.database_enabled' => ['nullable', 'boolean'],
        ]);

        foreach ($validated['settings'] as $setting) {
            NotificationSetting::updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'notification_type' => $setting['notification_type'],
                ],
                [
                    'mail_enabled' => (bool) ($setting['mail_enabled'] ?? false),
                    'database_enabled' => (bool) ($setting['database_enabled'] ?? false),
                    'nextcloud_talk_enabled' => false,
                    'nextcloud_talk_webhook_url' => null,
                ],
            );
        }

        return back()->with('success', 'Notification preferences updated.');
    }

    private function authorizeNotification(Request $request, DatabaseNotification $notification): void
    {
        abort_unless(
            $notification->type === CustomerPortalNotification::class
                && $notification->notifiable_type === $request->user()::class
                && (int) $notification->notifiable_id === (int) $request->user()->id,
            404,
        );
    }

    private function safeRedirectUrl(?string $url): string
    {
        if (blank($url)) {
            return route('customer-portal.notifications.index');
        }

        $portalRoot = url('/portal');

        if (str_starts_with($url, $portalRoot) || str_starts_with($url, '/portal')) {
            return $url;
        }

        return route('customer-portal.dashboard');
    }

    private function context(Request $request): CustomerPortalContext
    {
        /** @var CustomerPortalContext $context */
        $context = $request->attributes->get('customerPortalContext');

        return $context;
    }
}
