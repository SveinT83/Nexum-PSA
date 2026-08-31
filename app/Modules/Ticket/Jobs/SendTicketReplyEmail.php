<?php

namespace App\Modules\Ticket\Jobs;

use App\Models\Clients\ClientUser;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Email\Services\EmailProviderBindingSnapshot;
use App\Modules\Email\Services\EmailSendOutcomeUnresolvedException;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class SendTicketReplyEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const REPLY_ABOVE_LINE = '--- Please reply above this line ---';

    public int $timeout = 120;

    public ?int $emailAccountId = null;

    public ?int $providerBindingVersion = null;

    /*
    |--------------------------------------------------------------------------
    | Ticket reply outbound email job
    |--------------------------------------------------------------------------
    |
    | Customer replies are queued so the technician does not wait for SMTP.
    | The job resolves the default ticket email account, renders the seeded
    | ticket_reply template, sends SMTP, and logs the outcome.
    |
    */
    public function __construct(public int $ticketMessageId)
    {
        $snapshot = app(EmailProviderBindingSnapshot::class)->captureScope('tickets');
        $this->emailAccountId = $snapshot['account_id'];
        $this->providerBindingVersion = $snapshot['provider_binding_version'];
    }

    public function handle(
        DefaultEmailAccountResolver $accountResolver,
        EmailTemplateRenderer $renderer,
        SmtpAccountMailer $mailer
    ): void {
        $message = TicketMessage::with(['ticket.contact', 'ticket.priority', 'ticket.status', 'fileAttachments'])->find($this->ticketMessageId);

        if (! $message || $message->type !== 'customer_reply') {
            return;
        }

        $ticket = $message->ticket;
        $contact = $this->recipientContact($message);

        if (! $ticket || empty($contact?->email)) {
            $this->log(null, $message->id, 'error', 'TICKET_EMAIL_NO_CONTACT', 'Ticket reply has no contact email.');

            return;
        }

        $account = $accountResolver->forScope('tickets');

        if (! $account) {
            $this->log(null, $message->id, 'error', 'TICKET_EMAIL_NO_ACCOUNT', 'No active ticket outbound email account is configured.');

            return;
        }

        try {
            $account = app(EmailProviderBindingSnapshot::class)->resolveScope(
                'tickets',
                $this->emailAccountId,
                $this->providerBindingVersion,
            );
        } catch (\App\Modules\Integration\Exceptions\EmailProviderSecurityException) {
            $this->log($account->id, $message->id, 'error', 'TICKET_EMAIL_PROVIDER_BINDING_STALE', 'The outbound Email provider binding changed after this job was dispatched.');

            return;
        }

        $template = EmailTemplate::query()
            ->where('scope', 'tickets')
            ->where('key', 'ticket_reply')
            ->where('is_active', true)
            ->first();

        if (! $template) {
            $this->log($account->id, $message->id, 'error', 'TICKET_EMAIL_NO_TEMPLATE', 'No active ticket_reply email template exists.');

            return;
        }

        $idempotencyKey = 'ticket-reply:'.$message->id;

        if (EmailLog::query()->where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }

        try {
            $rendered = $renderer->render($template, [
                'ticket_key' => $ticket->ticket_key,
                'ticket_subject' => $ticket->subject,
                'contact_name' => $contact->name,
                'message_body' => $message->body,
                'technician_name' => $message->author?->name ?? 'Support',
            ]);
            $attachments = $this->attachmentsForMailer($message);
            $ccRecipients = $this->ccRecipients($message);
            $reservedMessageId = $mailer->generateMessageId($account);
        } catch (\Throwable) {
            // Preparation happened before SMTP, so no provider outcome is
            // ambiguous and a queue retry remains safe.
            $this->log(
                $account->id,
                $message->id,
                'error',
                'TICKET_EMAIL_PREPARATION_FAILED',
                'Ticket reply preparation failed before SMTP. A retry is safe.',
            );

            throw new \RuntimeException('Ticket reply preparation failed before SMTP. A retry is safe.');
        }

        $log = EmailLog::query()->createOrFirst(
            ['idempotency_key' => $idempotencyKey],
            [
                'direction' => 'outbound',
                'account_id' => $account->id,
                'scope' => 'tickets',
                'level' => 'warning',
                'code' => 'TICKET_EMAIL_RESERVED',
                'message' => 'Ticket reply Email reserved before SMTP.',
                'context_json' => [
                    'ticket_message_id' => $message->id,
                    'ticket_id' => $ticket->id,
                    'provider_binding_version' => $this->providerBindingVersion,
                ],
                'rfc_message_id' => $reservedMessageId,
            ],
        );

        // A prior reservation means delivery either completed or may have
        // started. Never call SMTP again until explicit reconciliation proves
        // the original provider outcome.
        if (! $log->wasRecentlyCreated) {
            return;
        }

        try {
            $messageId = $mailer->send(
                $account,
                $contact->email,
                $contact->name,
                $rendered['subject'],
                $this->appendReplyBoundaryToHtml($rendered['html']),
                $this->appendReplyBoundaryToText($rendered['text']),
                $attachments,
                $ccRecipients,
                [
                    'message_id' => $reservedMessageId,
                    'provider_binding_version' => $this->providerBindingVersion,
                ],
            );

            $log->forceFill([
                'level' => 'info',
                'code' => 'TICKET_EMAIL_SENT',
                'message' => 'Ticket reply Email accepted by SMTP.',
                'rfc_message_id' => $this->cleanMessageId($messageId) ?: $reservedMessageId,
                'context_json' => array_merge($log->context_json ?? [], [
                    'smtp_delivery' => 'accepted',
                    'attachments_count' => count($attachments),
                    'accepted_at' => now()->toISOString(),
                ]),
            ])->save();
        } catch (\Throwable) {
            $account->forceFill([
                'last_error_code' => 'SMTP_SEND',
                'last_error_message' => 'The ticket Email provider outcome could not be confirmed. Review Sent mail before retrying.',
            ])->save();

            $log->forceFill([
                'level' => 'error',
                'code' => 'TICKET_EMAIL_OUTCOME_UNRESOLVED',
                'message' => 'The ticket reply provider outcome could not be confirmed. Do not resend until Sent mail is reconciled.',
                'context_json' => array_merge($log->context_json ?? [], [
                    'smtp_delivery' => 'unresolved',
                    'failed_at' => now()->toISOString(),
                ]),
            ])->save();

            // Sever the provider exception chain because it may contain an
            // endpoint, username, recipient or provider response.
            throw new EmailSendOutcomeUnresolvedException(
                'The ticket reply provider outcome could not be confirmed. Do not resend until Sent mail is reconciled.',
            );
        }
    }

    private function cleanMessageId(?string $messageId): ?string
    {
        $messageId = trim((string) preg_replace('/[\r\n]+/', '', (string) $messageId));

        return $messageId !== '' ? $messageId : null;
    }

    private function recipientContact(TicketMessage $message): ?ClientUser
    {
        $ticket = $message->ticket;
        $replyContactId = $message->metadata['reply_contact_id'] ?? null;

        if ($replyContactId && $ticket?->client_id) {
            $contact = ClientUser::query()
                ->whereKey($replyContactId)
                ->whereHas('site', fn ($query) => $query->where('client_id', $ticket->client_id))
                ->where('active', true)
                ->first();

            if ($contact) {
                return $contact;
            }
        }

        return $ticket?->contact;
    }

    private function ccRecipients(TicketMessage $message): array
    {
        return collect($message->metadata['cc'] ?? [])
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->map(fn ($email) => ['email' => $email, 'name' => ''])
            ->values()
            ->all();
    }

    private function appendReplyBoundaryToHtml(string $html): string
    {
        $boundary = '<p style="margin-top:24px;color:#6c757d;font-size:12px;">'.e(self::REPLY_ABOVE_LINE).'</p>';

        return str_contains($html, self::REPLY_ABOVE_LINE) ? $html : rtrim($html).$boundary;
    }

    private function appendReplyBoundaryToText(string $text): string
    {
        return str_contains($text, self::REPLY_ABOVE_LINE)
            ? $text
            : rtrim($text)."\n\n".self::REPLY_ABOVE_LINE;
    }

    private function attachmentsForMailer(TicketMessage $message): array
    {
        return $message->fileAttachments
            ->map(function ($attachment) {
                $disk = $attachment->disk ?: 'local';

                if (! $attachment->path || ! Storage::disk($disk)->exists($attachment->path)) {
                    return null;
                }

                // Local disks can stream from a filesystem path; other disks fall back to in-memory content.
                if ($disk === 'local' && method_exists(Storage::disk($disk), 'path')) {
                    return [
                        'path' => Storage::disk($disk)->path($attachment->path),
                        'filename' => $attachment->filename,
                        'content_type' => $attachment->content_type,
                    ];
                }

                return [
                    'data' => Storage::disk($disk)->get($attachment->path),
                    'filename' => $attachment->filename,
                    'content_type' => $attachment->content_type,
                ];
            })
            ->filter()
            ->values()
            ->all();
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
