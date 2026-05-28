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
        if (!$user) {
            $this->unreadCount = 0;
            $this->notifications = collect();
            return;
        }

        $this->notifications = $user->unreadNotifications()
            ->latest()
            ->take(10)
            ->get();

        $this->unreadCount = $this->notifications->count();
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
        $url = $notification->data['url'] ?? null;

        $notification->markAsRead();
        $this->loadNotifications();

        if (filled($url) && $url !== '#') {
            return redirect()->to($url);
        }

        return null;
    }

    public function markAllAsRead()
    {
        Auth::user()->unreadNotifications->markAsRead();
        $this->loadNotifications();
    }

    public function render()
    {
        return view('notification::Livewire.notification-bell');
    }
}
