<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Notification\Models\NotificationInboundEmailScope;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Support\Collection;

class ResolveInboundEmailNotificationRecipients
{
    public const TYPE_TICKET_CUSTOMER_REPLY_RECEIVED = 'ticket_customer_reply_received';

    public const TYPE_INBOUND_EMAIL_RECEIVED = 'inbound_email_received';

    /**
     * @return Collection<int, array{user: User, notification_type: string}>
     */
    public function handle(EmailMessage $email): Collection
    {
        $email->loadMissing(['tags']);
        $ticket = $email->ticket_id ? Ticket::query()->with('owner')->find($email->ticket_id) : null;
        $recipients = collect();

        if ($ticket?->owner && $this->canReceiveTicketOwnerNotification($ticket->owner)) {
            $recipients->put($ticket->owner->id, [
                'user' => $ticket->owner,
                'notification_type' => self::TYPE_TICKET_CUSTOMER_REPLY_RECEIVED,
            ]);
        }

        foreach ($this->inboundSubscribers($email, $ticket) as $subscriber) {
            if ($recipients->has($subscriber->id)) {
                continue;
            }

            $recipients->put($subscriber->id, [
                'user' => $subscriber,
                'notification_type' => self::TYPE_INBOUND_EMAIL_RECEIVED,
            ]);
        }

        return $recipients->values();
    }

    private function canReceiveTicketOwnerNotification(User $user): bool
    {
        return $user->isActive() && $user->can('ticket.view');
    }

    /**
     * @return Collection<int, User>
     */
    private function inboundSubscribers(EmailMessage $email, ?Ticket $ticket): Collection
    {
        $settings = NotificationSetting::query()
            ->with('user')
            ->where('notification_type', self::TYPE_INBOUND_EMAIL_RECEIVED)
            ->get()
            ->filter(function (NotificationSetting $setting) use ($email, $ticket): bool {
                $user = $setting->user;

                if (! $user?->isActive() || ! $this->hasAnyEnabledChannel($setting)) {
                    return false;
                }

                if ($ticket) {
                    return $user->can('ticket.view') && $this->scopeAllows($setting, $email, $ticket);
                }

                return $user->can('email.inbox_view') && $this->scopeAllows($setting, $email, null);
            });

        return $settings
            ->pluck('user')
            ->filter()
            ->values();
    }

    private function hasAnyEnabledChannel(NotificationSetting $setting): bool
    {
        return (bool) (
            $setting->database_enabled
            || $setting->mail_enabled
            || $setting->web_push_enabled
            || $setting->nextcloud_talk_enabled
        );
    }

    private function scopeAllows(NotificationSetting $setting, EmailMessage $email, ?Ticket $ticket): bool
    {
        $scopes = NotificationInboundEmailScope::query()
            ->where('user_id', $setting->user_id)
            ->where('notification_type', self::TYPE_INBOUND_EMAIL_RECEIVED)
            ->get();

        if ($scopes->isEmpty()) {
            return true;
        }

        return $scopes->contains(function (NotificationInboundEmailScope $scope) use ($email, $ticket): bool {
            return match ($scope->scope_kind) {
                NotificationInboundEmailScope::KIND_EMAIL_ACCOUNT => (int) $scope->scope_id === (int) $email->account_id,
                NotificationInboundEmailScope::KIND_TICKET_QUEUE => $ticket
                    && (int) $scope->scope_id === (int) $ticket->queue_id,
                default => false,
            };
        });
    }
}
