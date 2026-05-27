<?php

namespace App\Modules\Notification\Notifications;

use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a comment/message is added to a ticket.
 */
class TicketCommentAdded extends Notification
{
    use Queueable;

    public function __construct(
        public Ticket $ticket,
        public string $commentAuthor,
        public string $commentPreview,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = [];
        $setting = NotificationSetting::getForUser($notifiable, 'ticket_comment_added');

        if ($setting->database_enabled) {
            $channels[] = 'database';
        }
        if ($setting->mail_enabled) {
            $channels[] = 'mail';
        }

        $talkChannel = \App\Modules\Notification\Models\NotificationChannel::getByDriver('nextcloud_talk');
        if ($talkChannel?->is_enabled && $setting->nextcloud_talk_enabled) {
            $channels[] = \App\Modules\Notification\Channels\NextcloudTalkChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticketUrl = route('tech.tickets.show', $this->ticket->ticket_key);

        return (new MailMessage)
            ->subject("[Ticket {$this->ticket->ticket_key}] New comment by {$this->commentAuthor}")
            ->greeting("Hello {$notifiable->name},")
            ->line("**{$this->commentAuthor}** commented on ticket **{$this->ticket->ticket_key}**.")
            ->line("**Subject:** {$this->ticket->subject}")
            ->line("> " . str($this->commentPreview)->limit(150))
            ->action('View Ticket', $ticketUrl);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'ticket_comment_added',
            'ticket_key' => $this->ticket->ticket_key,
            'ticket_subject' => $this->ticket->subject,
            'comment_author' => $this->commentAuthor,
            'comment_preview' => str($this->commentPreview)->limit(150),
            'url' => route('tech.tickets.show', $this->ticket->ticket_key),
        ];
    }

    public function toNextcloudTalk(object $notifiable): array
    {
        return [
            'title' => "Comment on {$this->ticket->ticket_key}",
            'message' => "{$this->commentAuthor}: \"{$this->commentPreview}\"",
        ];
    }
}