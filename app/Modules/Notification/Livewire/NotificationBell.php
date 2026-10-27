<?php

namespace App\Modules\Notification\Livewire;

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

        $query = app(\App\Modules\Notification\Actions\ManageNotificationReads::class)->query($user)->whereNull('read_at');
        $this->unreadCount = (clone $query)->count();
        $this->notifications = $query->latest()->limit(10)->get();
    }

    public function snoozeWorkday(string $id, int $generation): void
    {
        app(\App\Modules\Workday\Actions\WorkdayReminders::class)->snooze(Auth::user(), $id, $generation);
        $this->loadNotifications();
    }

    public function markAsRead(string $notificationId)
    {
        app(\App\Modules\Notification\Actions\ManageNotificationReads::class)->read(Auth::user(), $notificationId);
        $this->loadNotifications();
    }

    public function openNotification(string $notificationId)
    {
        $result = app(\App\Modules\Notification\Actions\OpenNotification::class)->handle(Auth::user(), $notificationId);
        $this->loadNotifications();

        return redirect()->to($result['target_url']);
    }

    public function markAllAsRead()
    {
        app(\App\Modules\Notification\Actions\ManageNotificationReads::class)->readAll(Auth::user());
        $this->loadNotifications();
    }

    public function render()
    {
        return view('notification::Livewire.notification-bell', [
            'workdayReminders' => Auth::user() ? collect(app(\App\Modules\Workday\Actions\WorkdayReminders::class)->pending(Auth::user()))->keyBy('notification_id') : collect(),
        ]);
    }
}
