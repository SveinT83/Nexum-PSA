<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Notifications\InboundEmailRoutedNotification;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Support\Str;

class DispatchInboundEmailNotification
{
    public function __construct(
        private readonly ResolveInboundEmailNotificationRecipients $recipients,
        private readonly RecordCanonicalNotification $recordNotification,
    ) {}

    public function handle(EmailMessage $email): void
    {
        $email = $email->fresh() ?? $email;
        $email->loadMissing(['tags']);

        if ($this->isSuppressed($email)) {
            return;
        }

        $ticket = $email->ticket_id
            ? Ticket::query()->with(['owner', 'queue'])->find($email->ticket_id)
            : null;
        $ticketMessage = $ticket
            ? $this->ticketMessageForEmail($email, $ticket)
            : null;

        foreach ($this->recipients->handle($email) as $recipient) {
            /** @var User $user */
            $user = $recipient['user'];
            $notificationType = $recipient['notification_type'];
            $setting = NotificationSetting::getForUser($user, $notificationType);

            if (! $this->hasAnyEnabledChannel($setting)) {
                continue;
            }

            $payload = $this->payload($email, $ticket, $ticketMessage, $user, $notificationType);
            $canonical = $this->recordNotification->handle(
                user: $user,
                notificationClass: InboundEmailRoutedNotification::class,
                deliveryIdentity: (string) $payload['delivery_identity'],
                data: $payload,
                unread: (bool) $setting->database_enabled,
            );

            if ($setting->mail_enabled || $setting->web_push_enabled || $setting->nextcloud_talk_enabled) {
                $user->notify(new InboundEmailRoutedNotification($payload, $canonical->id));
            }
        }
    }

    private function isSuppressed(EmailMessage $email): bool
    {
        if ($email->state === 'archived') {
            return true;
        }

        return $email->tags->contains(
            fn (Tag $tag): bool => in_array(strtolower((string) ($tag->slug ?: $tag->name)), ['spam', 'junk', 'not-ticket'], true)
        );
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

    private function ticketMessageForEmail(EmailMessage $email, Ticket $ticket): ?TicketMessage
    {
        return TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('metadata->email_message_id', $email->id)
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        EmailMessage $email,
        ?Ticket $ticket,
        ?TicketMessage $ticketMessage,
        User $user,
        string $notificationType,
    ): array {
        $deliveryIdentity = 'inbound-email:'.$email->id.':user:'.$user->id;
        $targetUrl = $ticket
            ? route('tech.tickets.show', $ticket, false)
            : route('tech.inbox.show', $email, false);
        $actionLabel = $ticket ? 'Open Ticket' : 'Open Email';
        $ticketKey = $ticket?->ticket_key;
        $title = $notificationType === ResolveInboundEmailNotificationRecipients::TYPE_TICKET_CUSTOMER_REPLY_RECEIVED
            ? 'Customer reply received'.($ticketKey ? ' on '.$ticketKey : '')
            : 'New inbound Email';

        return [
            'type' => $notificationType,
            'delivery_identity' => $deliveryIdentity,
            'title' => $title,
            'ticket_id' => $ticket?->id,
            'ticket_key' => $ticketKey,
            'ticket_message_id' => $ticketMessage?->id,
            'ticket_queue_id' => $ticket?->queue_id,
            'email_message_id' => $email->id,
            'email_account_id' => $email->account_id,
            'source_type' => $ticket ? 'ticket_message' : 'email_message',
            'source_id' => $ticketMessage?->id ?? $email->id,
            'source_label' => $ticket ? 'Ticket customer reply' : 'Inbox email',
            'url' => $targetUrl,
            'action_label' => $actionLabel,
            'mail_summary' => $this->mailSummary($email, $ticket),
            'push_title' => 'Nexum',
            'push_body' => $ticket
                ? 'A customer reply is ready in Nexum.'
                : 'A new inbound email is ready in Nexum.',
            'preview_sender_name' => Str::limit(trim((string) $email->from_name), 80, ''),
            'preview_subject' => Str::limit(trim((string) $email->subject), 100, ''),
            'web_push_tag' => $this->webPushTag($notificationType, $email->id, $user->id),
        ];
    }

    private function mailSummary(EmailMessage $email, ?Ticket $ticket): string
    {
        if ($ticket) {
            return 'A customer reply was linked to Ticket '.$ticket->ticket_key.'.';
        }

        return 'A new inbound email is available in the Nexum inbox.';
    }

    private function webPushTag(string $notificationType, int $emailMessageId, int $userId): string
    {
        return 'nexum-'.$notificationType.'-'.$emailMessageId.'-'.$userId;
    }
}
