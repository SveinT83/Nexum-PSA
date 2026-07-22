<?php

namespace App\Modules\Ticket\Jobs;

use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Support\TicketWorkflowCustomerNotificationPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTicketWorkflowCustomerUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $ticketMessageId) {}

    public function handle(
        DefaultEmailAccountResolver $accountResolver,
        EmailTemplateRenderer $renderer,
        SmtpAccountMailer $mailer,
        SendCustomerPortalNotification $portalNotifications,
    ): void {
        $message = TicketMessage::with(['ticket.contact', 'ticket.client', 'author'])->find($this->ticketMessageId);
        $ticket = $message?->ticket;
        $context = (array) data_get($message?->metadata, 'customer_status_update', []);
        $policy = TicketWorkflowCustomerNotificationPolicy::normalize($context['policy'] ?? null);

        if (! $message || $message->type !== 'status_update' || ! $ticket || ! $policy['enabled']) {
            return;
        }

        if (! $ticket->isPortalVisible()) {
            $this->recordDelivery($message, 'all', 'skipped', 'Ticket is no longer Published.');
            $this->event($ticket, $message, 'workflow_customer_update_skipped', 'Customer status update skipped because the Ticket is not Published.');

            return;
        }

        $results = [];
        if (in_array(TicketWorkflowCustomerNotificationPolicy::CHANNEL_PORTAL, $policy['channels'], true)) {
            $results['portal'] = $this->sendPortal($message, $ticket, $context, $portalNotifications);
        }

        if (in_array(TicketWorkflowCustomerNotificationPolicy::CHANNEL_EMAIL, $policy['channels'], true)) {
            $results['email'] = $this->sendEmail($message, $ticket, $context, $policy, $accountResolver, $renderer, $mailer);
        }

        $failed = collect($results)->contains('failed');
        $sent = collect($results)->contains('sent');
        $status = $failed ? ($sent ? 'partial' : 'failed') : ($sent ? 'sent' : 'skipped');
        $this->event(
            $ticket,
            $message,
            'workflow_customer_update_'.$status,
            match ($status) {
                'sent' => 'Customer status update delivered.',
                'partial' => 'Customer status update was only partly delivered.',
                'failed' => 'Customer status update delivery failed.',
                default => 'Customer status update had no eligible delivery target.',
            },
            ['results' => $results],
        );
    }

    private function sendPortal(
        TicketMessage $message,
        Ticket $ticket,
        array $context,
        SendCustomerPortalNotification $portalNotifications,
    ): string {
        if (filled(data_get($message->metadata, 'customer_status_update.delivery.portal.completed_at'))) {
            return (string) data_get($message->metadata, 'customer_status_update.delivery.portal.status', 'sent');
        }

        if (! $ticket->client_id) {
            $this->recordDelivery($message, 'portal', 'failed', 'Ticket has no Client.');

            return 'failed';
        }

        $recipientCount = $portalNotifications->handle(
            type: 'portal_ticket_status_changed',
            clientId: (int) $ticket->client_id,
            siteId: $ticket->site_id ? (int) $ticket->site_id : null,
            title: 'Ticket '.$ticket->ticket_key.' status updated',
            body: (string) ($context['customer_message'] ?? 'Your Ticket status was updated.'),
            url: route('customer-portal.tickets.show', $ticket),
            sourceType: Ticket::class,
            sourceId: $ticket->id,
            metadata: [
                'ticket_key' => $ticket->ticket_key,
                'ticket_message_id' => $message->id,
                'previous_status' => $context['previous_status'] ?? null,
                'current_status' => $context['current_status'] ?? null,
            ],
            channels: ['database'],
        );

        $status = $recipientCount > 0 ? 'sent' : 'skipped';
        $this->recordDelivery(
            $message,
            'portal',
            $status,
            $recipientCount > 0 ? 'Customer portal notification created.' : 'No eligible Customer portal recipient.',
            ['recipient_count' => $recipientCount],
        );

        return $status;
    }

    private function sendEmail(
        TicketMessage $message,
        Ticket $ticket,
        array $context,
        array $policy,
        DefaultEmailAccountResolver $accountResolver,
        EmailTemplateRenderer $renderer,
        SmtpAccountMailer $mailer,
    ): string {
        if (EmailLog::query()
            ->where('direction', 'outbound')
            ->where('scope', 'tickets')
            ->where('code', 'TICKET_STATUS_UPDATE_SENT')
            ->where('context_json->ticket_message_id', $message->id)
            ->exists()) {
            return 'sent';
        }

        $contact = $ticket->contact;
        if (! $contact?->active || empty($contact->email)) {
            $this->emailLog(null, $message, 'error', 'TICKET_STATUS_UPDATE_NO_CONTACT', 'Ticket status update has no active contact email.');
            $this->recordDelivery($message, 'email', 'failed', 'No active Ticket contact email.');

            return 'failed';
        }

        $account = $accountResolver->forScope('tickets');
        if (! $account) {
            $this->emailLog(null, $message, 'error', 'TICKET_STATUS_UPDATE_NO_ACCOUNT', 'No active ticket outbound email account is configured.');
            $this->recordDelivery($message, 'email', 'failed', 'No active Ticket Email account.');

            return 'failed';
        }

        $template = EmailTemplate::query()
            ->where('scope', 'tickets')
            ->where('key', $policy['email_template_key'])
            ->where('is_active', true)
            ->first();
        if (! $template) {
            $this->emailLog($account->id, $message, 'error', 'TICKET_STATUS_UPDATE_NO_TEMPLATE', 'The selected Ticket status update template is unavailable.');
            $this->recordDelivery($message, 'email', 'failed', 'Selected Email template is unavailable.');

            return 'failed';
        }

        try {
            $rendered = $renderer->render($template, [
                'ticket_key' => $ticket->ticket_key,
                'ticket_subject' => $ticket->subject,
                'contact_name' => $contact->name,
                'previous_status' => $context['previous_status'] ?? 'Previous status',
                'current_status' => $context['current_status'] ?? 'Current status',
                'status_message' => $context['customer_message'] ?? 'Your Ticket status was updated.',
                'technician_name' => $message->author?->name ?? 'Support',
            ]);
            $rfcMessageId = $mailer->send(
                $account,
                $contact->email,
                $contact->name,
                $rendered['subject'],
                $rendered['html'],
                $rendered['text'],
            );
            $this->emailLog($account->id, $message, 'info', 'TICKET_STATUS_UPDATE_SENT', 'Ticket status update email sent.', [
                'ticket_id' => $ticket->id,
                'ticket_key' => $ticket->ticket_key,
                'to' => $contact->email,
                'workflow_history_id' => data_get($message->metadata, 'workflow_history_id'),
            ], $rfcMessageId);
            $this->recordDelivery($message, 'email', 'sent', 'Ticket status update Email sent.', [
                'rfc_message_id' => $rfcMessageId,
            ]);

            return 'sent';
        } catch (\Throwable $exception) {
            $account->forceFill([
                'last_error_code' => 'SMTP_SEND',
                'last_error_message' => $exception->getMessage(),
            ])->save();
            $this->emailLog($account->id, $message, 'error', 'TICKET_STATUS_UPDATE_SEND_FAILED', $exception->getMessage(), [
                'ticket_id' => $ticket->id,
                'ticket_key' => $ticket->ticket_key,
                'to' => $contact->email,
            ]);
            $this->recordDelivery($message, 'email', 'failed', $exception->getMessage());
            $this->event($ticket, $message, 'workflow_customer_update_failed', 'Customer status update Email delivery failed.');

            throw $exception;
        }
    }

    /** @param array<string, mixed> $context */
    private function recordDelivery(TicketMessage $message, string $channel, string $status, string $detail, array $context = []): void
    {
        $metadata = $message->fresh()->metadata ?? [];
        $metadata['customer_status_update']['delivery'][$channel] = array_merge([
            'status' => $status,
            'detail' => $detail,
            'completed_at' => now()->toISOString(),
        ], $context);
        $message->forceFill(['metadata' => $metadata])->save();
    }

    /** @param array<string, mixed> $context */
    private function emailLog(?int $accountId, TicketMessage $message, string $level, string $code, string $text, array $context = [], ?string $rfcMessageId = null): void
    {
        EmailLog::query()->create([
            'direction' => 'outbound',
            'account_id' => $accountId,
            'scope' => 'tickets',
            'level' => $level,
            'code' => $code,
            'message' => $text,
            'context_json' => array_merge(['ticket_message_id' => $message->id], $context),
            'rfc_message_id' => $rfcMessageId,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function event(Ticket $ticket, TicketMessage $message, string $type, string $text, array $metadata = []): void
    {
        TicketEvent::query()->create([
            'ticket_id' => $ticket->id,
            'actor_id' => $message->author_id,
            'type' => $type,
            'message' => $text,
            'metadata' => array_merge([
                'ticket_message_id' => $message->id,
                'workflow_history_id' => data_get($message->metadata, 'workflow_history_id'),
            ], $metadata),
        ]);
    }
}
