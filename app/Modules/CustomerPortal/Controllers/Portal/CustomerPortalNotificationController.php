<?php

namespace App\Modules\CustomerPortal\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Notifications\CustomerPortalNotification;
use App\Modules\Notification\Support\NotificationTypeRegistry;
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
            'types' => NotificationTypeRegistry::labels(NotificationTypeRegistry::AUDIENCE_CUSTOMER_PORTAL),
            'settings' => NotificationSetting::getAllForUser(
                $request->user(),
                array_keys(NotificationTypeRegistry::labels(NotificationTypeRegistry::AUDIENCE_CUSTOMER_PORTAL)),
            ),
        ]);
    }

    public function open(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        return redirect()->to(app(\App\Modules\Notification\Actions\OpenNotification::class)
            ->handle($request->user(), $notification->id, true)['target_url']);
    }

    public function markRead(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $this->authorizeNotification($request, $notification);

        app(\App\Modules\Notification\Actions\ManageNotificationReads::class)->read($request->user(), $notification->id, true);

        return back()->with('success', 'Notification marked as read.');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        app(\App\Modules\Notification\Actions\ManageNotificationReads::class)->readAll($request->user(), true);

        return back()->with('success', 'All portal notifications were marked as read.');
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        app(\App\Modules\Notification\Actions\ManageNotificationPreferences::class)->update($request, true);

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
