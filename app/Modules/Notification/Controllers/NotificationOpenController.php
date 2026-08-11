<?php

namespace App\Modules\Notification\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Notification\Notifications\InboundEmailRoutedNotification;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationOpenController extends Controller
{
    public function __invoke(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $user = $request->user();

        abort_unless(
            $user
                && $notification->notifiable_type === $user::class
                && (int) $notification->notifiable_id === (int) $user->id,
            404
        );

        $target = $notification->type === InboundEmailRoutedNotification::class
            ? $this->currentInboundSourceUrl($notification)
            : null;
        $target ??= $this->safeRelativeUrl($notification->data['url'] ?? null)
            ?? route('tech.profile.notifications', [], false);

        if ($notification->type !== InboundEmailRoutedNotification::class) {
            $notification->markAsRead();
        }

        return redirect()->to($target);
    }

    private function safeRelativeUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || str_starts_with($url, '//')) {
            return null;
        }

        if (! str_starts_with($url, '/')) {
            return null;
        }

        return $url;
    }

    private function currentInboundSourceUrl(DatabaseNotification $notification): ?string
    {
        $data = $notification->data;
        $emailMessageId = (int) ($data['email_message_id'] ?? 0);

        if ($emailMessageId > 0) {
            $email = EmailMessage::query()->find($emailMessageId);

            if ($email?->ticket_id) {
                $ticket = Ticket::query()->find($email->ticket_id);

                return $ticket ? route('tech.tickets.show', $ticket, false) : null;
            }

            if ($email) {
                return route('tech.inbox.show', $email, false);
            }
        }

        $ticketId = (int) ($data['ticket_id'] ?? 0);

        if ($ticketId > 0 && $ticket = Ticket::query()->find($ticketId)) {
            return route('tech.tickets.show', $ticket, false);
        }

        return null;
    }
}
