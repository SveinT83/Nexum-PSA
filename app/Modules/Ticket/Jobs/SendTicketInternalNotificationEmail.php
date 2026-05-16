<?php

namespace App\Modules\Ticket\Jobs;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTicketInternalNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $ticketMessageId)
    {
    }

    public function handle(DefaultEmailAccountResolver $accountResolver, SmtpAccountMailer $mailer): void
    {
        $message = TicketMessage::with(['ticket', 'author'])->find($this->ticketMessageId);
        $notifyUserId = $message?->metadata['notify_user_id'] ?? null;
        $recipient = $notifyUserId ? User::query()->whereKey($notifyUserId)->where('status', User::STATUS_ACTIVE)->first() : null;

        if (! $message || $message->type !== 'internal_note' || ! $recipient?->email) {
            return;
        }

        $account = $accountResolver->forScope('tickets');

        if (! $account) {
            $this->log(null, $message->id, 'error', 'TICKET_INTERNAL_NOTIFY_NO_ACCOUNT', 'No active ticket outbound email account is configured.');
            return;
        }

        $ticket = $message->ticket;
        $subject = '[' . $ticket->ticket_key . '] Internal note notification';
        $author = $message->author?->name ?? 'A technician';
        $text = $author . " added an internal note:\n\n" . $message->body;
        $html = '<p>' . e($author) . ' added an internal note:</p><div style="white-space:pre-wrap;">' . e($message->body) . '</div>';

        $messageId = $mailer->send($account, $recipient->email, $recipient->name, $subject, $html, $text);

        $this->log($account->id, $message->id, 'info', 'TICKET_INTERNAL_NOTIFY_SENT', 'Ticket internal note notification sent.', [
            'ticket_id' => $ticket->id,
            'ticket_key' => $ticket->ticket_key,
            'to' => $recipient->email,
            'rfc_message_id' => $messageId,
        ], $messageId);
    }

    private function log(?int $accountId, int $messageId, string $level, string $code, string $message, array $context = [], ?string $rfcMessageId = null): void
    {
        EmailLog::create([
            'direction' => 'outbound',
            'account_id' => $accountId,
            'scope' => 'tickets',
            'level' => $level,
            'code' => $code,
            'message' => $message,
            'context_json' => array_merge(['ticket_message_id' => $messageId], $context),
            'rfc_message_id' => $rfcMessageId,
        ]);
    }
}
