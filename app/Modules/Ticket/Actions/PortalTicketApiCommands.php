<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\CustomerPortal\Actions\RecordCustomerPortalAudit;
use App\Modules\CustomerPortal\Models\CustomerPortalAuditEvent;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\CustomerPortal\Support\CustomerPortalContextResolver;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Support\PortalTicketAccess;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Portal-owned audit rows retain only fingerprints/result IDs; User locking serializes retries for this identity. */
final class PortalTicketApiCommands
{
    public function create(Request $request, array $data): Ticket
    {
        return DB::transaction(function () use ($request, $data) {
            $context = $this->currentContext($request);
            $fingerprint = $this->fingerprint($context, $data);
            $key = hash('sha256', $data['idempotency_key']);
            if ($receipt = $this->receipt($request, 'portal_api_ticket_created', $key)) {
                abort_unless(hash_equals((string) data_get($receipt->metadata, 'request_fingerprint'), $fingerprint), 409, 'The request key was already used for a different Portal Ticket.');
                $ticket = app(PortalTicketAccess::class)->visibleTickets($context)->findOrFail(data_get($receipt->metadata, 'ticket_id'));

                return $ticket;
            }
            if ($context->site && ! empty($data['site_id'])) {
                abort_unless((int) $data['site_id'] === (int) $context->site->id, 422, 'The Site must match the selected membership.');
            }
            $ticket = app(StorePortalTicket::class)->handle($request, $context, $data);
            $this->record($request, $context, 'portal_api_ticket_created', $key, $fingerprint, ['ticket_id' => $ticket->id]);
            $ticket->wasRecentlyCreated = true;

            return $ticket;
        }, 3);
    }

    public function reply(Request $request, Ticket $ticket, array $data): TicketMessage
    {
        return DB::transaction(function () use ($request, $ticket, $data) {
            $context = $this->currentContext($request);
            $ticket = app(PortalTicketAccess::class)->visibleTickets($context)->lockForUpdate()->findOrFail($ticket->id);
            $data['ticket_id'] = $ticket->id;
            $fingerprint = $this->fingerprint($context, $data);
            $key = hash('sha256', $data['idempotency_key']);
            if ($receipt = $this->receipt($request, 'portal_api_ticket_replied', $key)) {
                abort_unless(hash_equals((string) data_get($receipt->metadata, 'request_fingerprint'), $fingerprint), 409, 'The reply key was already used for a different Portal reply.');

                return $ticket->messages()->where('visibility', 'public')->findOrFail(data_get($receipt->metadata, 'message_id'));
            }
            $message = app(AddPortalTicketReply::class)->handle($request, $ticket, $context, $data['body']);
            $this->record($request, $context, 'portal_api_ticket_replied', $key, $fingerprint, ['ticket_id' => $ticket->id, 'message_id' => $message->id]);

            return $message;
        }, 3);
    }

    private function currentContext(Request $request): CustomerPortalContext
    {
        $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
        $selected = $request->attributes->get('customerPortalContext')->membership->id;
        $context = app(CustomerPortalContextResolver::class)->resolveForUser($actor, $selected);
        abort_unless($context && (int) $context->membership->id === (int) $selected, 403, 'The selected active Portal membership is unavailable.');
        $request->attributes->set('customerPortalContext', $context);

        return $context;
    }

    private function fingerprint(CustomerPortalContext $context, array $data): string
    {
        unset($data['idempotency_key']);

        return TicketRuleStableJson::checksum(['membership_id' => $context->membership->id, 'client_id' => $context->client->id, 'site_id' => $context->site?->id, 'data' => $data]);
    }

    private function receipt(Request $request, string $event, string $key): ?CustomerPortalAuditEvent
    {
        return CustomerPortalAuditEvent::query()->where('user_id', $request->user()->id)->where('event', $event)->where('metadata->request_key_hash', $key)->first();
    }

    private function record(Request $request, CustomerPortalContext $context, string $event, string $key, string $fingerprint, array $result): void
    {
        app(RecordCustomerPortalAudit::class)->handle($event, $context->account, $request->user(), $context->contact, $context->client, $context->site,
            $result + ['membership_id' => $context->membership->id, 'request_key_hash' => $key, 'request_fingerprint' => $fingerprint], $request);
    }
}
