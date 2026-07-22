<?php

namespace App\Modules\Sales\Actions;

use App\Models\Core\User;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Ticket\Actions\InvalidateTicketWorkflowReviews;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketWorkflowEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptSalesQuote
{
    public function handle(SalesQuoteVersion $version, array $data, ?User $actor = null): SalesQuoteVersion
    {
        return DB::transaction(function () use ($version, $data, $actor): SalesQuoteVersion {
            $locked = SalesQuoteVersion::query()
                ->with(['quote.opportunity', 'lines'])
                ->lockForUpdate()
                ->findOrFail($version->id);

            if ($locked->status === 'accepted') {
                return $locked;
            }

            if ($locked->status !== 'sent') {
                throw ValidationException::withMessages(['quote' => 'Only the current sent quote can be accepted.']);
            }

            if ($locked->expires_at && $locked->expires_at->isPast()) {
                throw ValidationException::withMessages(['quote' => 'This quote has expired.']);
            }

            $opportunity = $locked->quote->opportunity;
            if ((int) $opportunity->current_quote_version_id !== (int) $locked->id) {
                throw ValidationException::withMessages(['quote' => 'A newer quote version exists and must be accepted instead.']);
            }

            $acceptedAt = now();
            $locked->forceFill([
                'status' => 'accepted',
                'accepted_at' => $acceptedAt,
                'accepted_by_name' => $data['name'],
                'accepted_ip' => $data['ip'] ?? null,
                'accepted_ua' => $data['user_agent'] ?? null,
                'accepted_method' => $data['method'] ?? 'public_link',
                'accepted_by_user_id' => $actor?->id,
                'accepted_ticket_message_id' => $data['ticket_message_id'] ?? null,
                'acceptance_metadata' => $data['metadata'] ?? [],
                'portal_accepted_account_id' => $data['portal_account_id'] ?? $locked->portal_accepted_account_id,
                'portal_accepted_membership_id' => $data['portal_membership_id'] ?? $locked->portal_accepted_membership_id,
                'portal_accepted_contact_id' => $data['portal_contact_id'] ?? $locked->portal_accepted_contact_id,
            ])->save();
            $locked->quote->forceFill(['status' => 'accepted', 'current_version_id' => $locked->id])->save();
            $opportunity->forceFill([
                'status' => 'won',
                'probability_percent' => 100,
                'estimated_value_ex_vat' => $locked->total_ex_vat,
                'weighted_value_ex_vat' => $locked->total_ex_vat,
                'won_quote_version_id' => $locked->id,
                'won_at' => $acceptedAt,
            ])->save();

            SalesActivity::query()->create([
                'opportunity_id' => $opportunity->id,
                'actor_id' => $actor?->id,
                'type' => 'quote_accepted',
                'direction' => 'inbound',
                'subject' => 'Quote accepted',
                'body' => $data['name'].' accepted quote '.$locked->quote->quote_key.' v'.$locked->version_number.'.',
                'metadata' => [
                    'quote_version_id' => $locked->id,
                    'method' => $data['method'] ?? 'public_link',
                    'ticket_message_id' => $data['ticket_message_id'] ?? null,
                ],
            ]);

            $context = $opportunity->ticketSalesContext()->with('ticket')->first();
            if ($context?->ticket) {
                $ticket = $context->ticket;
                $plannedLineIds = $locked->lines
                    ->map(fn ($line) => (int) data_get($line->snapshot, 'ticket_planned_line_id'))
                    ->filter()->unique()->all();
                $ticket->plannedLines()->whereIn('id', $plannedLineIds)->whereIn('status', ['planned', 'quoted', 'approved'])->update([
                    'status' => 'approved',
                    'approved_quote_version_id' => $locked->id,
                    'updated_at' => now(),
                ]);

                TicketWorkflowEvidence::query()->firstOrCreate([
                    'ticket_id' => $ticket->id,
                    'evidence_type' => 'quote_acceptance',
                    'scope_key' => 'quote-version:'.$locked->id,
                    'source_type' => $locked->getMorphClass(),
                    'source_id' => $locked->id,
                ], [
                    'fingerprint' => hash('sha256', implode('|', [$locked->id, $locked->version_number, $locked->total_ex_vat, $acceptedAt->timestamp])),
                    'subject_name' => $data['name'],
                    'evidenced_at' => $acceptedAt,
                    'created_by' => $actor?->id,
                    'metadata' => ['method' => $data['method'] ?? 'public_link'],
                ]);

                app(InvalidateTicketWorkflowReviews::class)->handle($ticket, 'The customer acceptance and approved scope changed.', $actor);

                TicketEvent::query()->create([
                    'ticket_id' => $ticket->id,
                    'actor_id' => $actor?->id,
                    'type' => 'sales_quote_accepted',
                    'message' => 'Customer accepted '.$locked->quote->quote_key.' v'.$locked->version_number.'.',
                    'after' => [
                        'quote_version_id' => $locked->id,
                        'total_ex_vat' => $locked->total_ex_vat,
                        'method' => $data['method'] ?? 'public_link',
                    ],
                ]);
            }

            return $locked->refresh();
        });
    }
}
