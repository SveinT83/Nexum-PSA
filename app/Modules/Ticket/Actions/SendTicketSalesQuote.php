<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Sales\Actions\RecalculateSalesQuoteVersion;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SendTicketSalesQuote
{
    public function __construct(
        private readonly TicketActionGuard $guard,
        private readonly RecalculateSalesQuoteVersion $recalculate,
        private readonly AddTicketMessage $addMessage,
        private readonly StoreTicketAttachment $attachments,
    ) {}

    public function handle(Ticket $ticket, array $data, User $actor)
    {
        if ($reason = $this->guard->reason($ticket, TicketAction::SEND_QUOTE, $actor)) {
            throw ValidationException::withMessages(['quote' => $reason]);
        }

        $ticket->loadMissing('salesContext.opportunity.currentQuoteVersion.quote');
        $version = $ticket->salesContext?->opportunity?->currentQuoteVersion;

        if (! $version || $version->status !== 'draft') {
            throw ValidationException::withMessages(['quote' => 'A current draft Ticket quote is required.']);
        }

        if ($ticket->client_id && ! $ticket->isPortalVisible()) {
            throw ValidationException::withMessages(['quote' => 'Publish the Ticket to the customer portal before sending the quote as a customer reply.']);
        }

        if ($version->lines()->count() < 1) {
            throw ValidationException::withMessages(['quote' => 'Add at least one quote line before sending.']);
        }

        $sent = DB::transaction(function () use ($ticket, $data, $actor, $version) {
            $this->recalculate->handle($version);
            $version->loadMissing(['quote.opportunity.client', 'quote.opportunity.primaryContact', 'lines']);
            $pdf = $this->renderPdf($version);
            $path = 'sales/quote-snapshots/'.$version->quote->quote_key.'/v'.$version->version_number.'-'.hash('sha256', $pdf).'.pdf';
            Storage::disk('local')->put($path, $pdf);

            $version->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
                'updated_by' => $actor->id,
                'pdf_snapshot_disk' => 'local',
                'pdf_snapshot_path' => $path,
                'pdf_snapshot_sha256' => hash('sha256', $pdf),
            ])->save();
            $version->quote->forceFill(['status' => 'sent', 'current_version_id' => $version->id])->save();
            $opportunity = $version->quote->opportunity;
            $opportunity->forceFill([
                'status' => 'quote_sent',
                'probability_percent' => 50,
                'weighted_value_ex_vat' => round((float) $version->total_ex_vat * 0.5, 2),
                'current_quote_version_id' => $version->id,
            ])->save();

            $url = route('sales.quotes.public.view', $version->secure_token);
            $body = trim((string) ($data['body'] ?? ''));
            $body .= ($body === '' ? '' : "\n\n").'Quote '.$version->quote->quote_key.' v'.$version->version_number
                .' totals '.number_format((float) $version->total_ex_vat, 2, '.', ' ').' NOK ex VAT.'
                ."\nReview and accept: ".$url;

            $message = $this->addMessage->handle($ticket, [
                'type' => 'customer_reply',
                'visibility' => 'public',
                'subject' => $data['subject'] ?? 'Quote '.$version->quote->quote_key.' for '.$ticket->ticket_key,
                'body' => $body,
                'reply_intent' => TicketAction::CUSTOMER_UPDATE,
                'reply_contact_id' => $data['reply_contact_id'] ?? $ticket->contact_id,
                'cc' => $data['cc'] ?? null,
                'suppress_workflow_trigger' => true,
                'metadata' => [
                    'quote_version_id' => $version->id,
                    'quote_key' => $version->quote->quote_key,
                    'secure_quote_url' => $url,
                ],
            ], $actor);

            $attachment = $this->attachments->fromContent(
                $message,
                $version->quote->quote_key.'-v'.$version->version_number.'.pdf',
                $pdf,
                'application/pdf',
                $actor,
                'sales_quote_snapshot',
            );

            SalesActivity::query()->create([
                'opportunity_id' => $opportunity->id,
                'actor_id' => $actor->id,
                'type' => 'quote_sent_from_ticket',
                'subject' => 'Quote sent from Ticket',
                'body' => 'Quote sent as Ticket reply with immutable PDF and acceptance link.',
                'metadata' => ['ticket_id' => $ticket->id, 'ticket_message_id' => $message->id, 'quote_version_id' => $version->id],
            ]);

            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor->id,
                'type' => 'sales_quote_sent',
                'message' => 'Sales quote sent for customer approval.',
                'after' => [
                    'quote_version_id' => $version->id,
                    'ticket_message_id' => $message->id,
                    'attachment_id' => $attachment->id,
                    'pdf_sha256' => $version->pdf_snapshot_sha256,
                ],
            ]);

            return $version->refresh();
        });

        app(ApplyTicketWorkflowActionTrigger::class)->handle($ticket->refresh(), TicketAction::SEND_QUOTE, $actor);

        return $sent;
    }

    private function renderPdf($version): string
    {
        $html = view('sales::Public.quote-pdf', [
            'version' => $version,
            'opportunity' => $version->quote->opportunity,
        ])->render();
        $dompdf = new Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }
}
