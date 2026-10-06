<?php

namespace App\Modules\Notification\Livewire;

use App\Modules\Notification\Notifications\InboundEmailRoutedNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Livewire component that renders the notification bell icon
 * in the page header with an unread count badge and dropdown
 * showing recent notifications.
 */
class NotificationBell extends Component
{
    public int $unreadCount = 0;

    public $notifications;

    public function mount()
    {
        $this->loadNotifications();
    }

    public function loadNotifications()
    {
        $user = Auth::user();
        if (! $user) {
            $this->unreadCount = 0;
            $this->notifications = collect();

            return;
        }

        $query = $user->unreadNotifications();
        app(\App\Modules\Workday\Actions\WorkdayReminders::class)->filterNotifications($query, $user);
        $this->notifications = $query
            ->latest()
            ->take(10)
            ->get();

        $this->unreadCount = $this->notifications->count();
    }

    public function snoozeWorkday(string $id, int $generation): void
    {
        app(\App\Modules\Workday\Actions\WorkdayReminders::class)->snooze(Auth::user(), $id, $generation);
        $this->loadNotifications();
    }

    public function markAsRead(string $notificationId)
    {
        $notification = Auth::user()->notifications()->findOrFail($notificationId);
        $notification->markAsRead();

        $this->loadNotifications();
    }

    public function openNotification(string $notificationId)
    {
        $notification = Auth::user()->notifications()->findOrFail($notificationId);

        if ($notification->type === \App\Modules\Notification\Notifications\WorkdayReminderNotification::class) {
            return redirect()->route('tech.profile.notifications.open', $notification);
        }
        if ($notification->type !== InboundEmailRoutedNotification::class) {
            $url = $notification->data['url'] ?? null;

            $notification->markAsRead();
            $this->loadNotifications();

            if (filled($url) && $url !== '#') {
                return redirect()->to($url);
            }

            return null;
        }

        return redirect()->route('tech.profile.notifications.open', $notification);
    }

    public function markAllAsRead()
    {
        Auth::user()->unreadNotifications->markAsRead();
        $this->loadNotifications();
    }

    public function render()
    {
        return view('notification::Livewire.notification-bell', [
            'workdayReminders' => Auth::user() ? collect(app(\App\Modules\Workday\Actions\WorkdayReminders::class)->pending(Auth::user()))->keyBy('notification_id') : collect(),
        ]);
    }
}
