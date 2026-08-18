<?php

namespace App\Modules\Sales\Actions;

use App\Models\Core\User;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesQuoteAcceptanceSnapshot;
use App\Modules\Sales\Models\SalesQuoteConversionPlan;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Ticket\Actions\InvalidateTicketWorkflowReviews;
use App\Modules\Ticket\Actions\ProcessAcceptedTicketQuote;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketWorkflowEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class AcceptSalesQuote
{
    public function __construct(
        private readonly BuildSalesQuoteAcceptanceSnapshot $snapshotBuilder,
        private readonly ProcessAcceptedTicketQuote $ticketQuoteProcessor,
    ) {}

    public function handle(SalesQuoteVersion $version, array $data, ?User $actor = null): SalesQuoteVersion
    {
        $accepted = DB::transaction(function () use ($version, $data, $actor): SalesQuoteVersion {
            $locked = SalesQuoteVersion::query()
                ->with(['quote.opportunity', 'lines.optionGroup', 'optionGroups.lines', 'acknowledgements', 'acceptanceSnapshot'])
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
            $snapshot = $this->snapshotBuilder->handle($locked, array_merge($data, [
                'user_id' => $actor?->id,
            ]));
            $acceptedTotalExVat = (float) data_get($snapshot, 'totals.total_ex_vat', $locked->total_ex_vat);
            $acceptedTotalIncVat = (float) data_get($snapshot, 'totals.total_inc_vat', $locked->total_inc_vat);
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
                'snapshots' => array_merge($locked->snapshots ?: [], ['accepted_cpq' => $snapshot]),
            ])->save();
            $locked->quote->forceFill(['status' => 'accepted', 'current_version_id' => $locked->id])->save();
            $opportunity->forceFill([
                'status' => 'won',
                'probability_percent' => 100,
                'estimated_value_ex_vat' => $acceptedTotalExVat,
                'weighted_value_ex_vat' => $acceptedTotalExVat,
                'won_quote_version_id' => $locked->id,
                'won_at' => $acceptedAt,
            ])->save();

            $acceptanceSnapshot = SalesQuoteAcceptanceSnapshot::query()->create([
                'quote_version_id' => $locked->id,
                'accepted_at' => $acceptedAt,
                'accepted_by_name' => $data['name'],
                'accepted_by_email' => $data['email'] ?? null,
                'source_method' => $data['method'] ?? 'public_link',
                'source_user_id' => $actor?->id,
                'portal_account_id' => $data['portal_account_id'] ?? null,
                'portal_membership_id' => $data['portal_membership_id'] ?? null,
                'portal_contact_id' => $data['portal_contact_id'] ?? null,
                'selected_line_ids' => $snapshot['selected_line_ids'],
                'declined_line_ids' => $snapshot['declined_line_ids'],
                'selected_lines' => $snapshot['selected_lines'],
                'declined_lines' => $snapshot['declined_lines'],
                'acknowledgements' => $snapshot['acknowledgements'],
                'totals' => $snapshot['totals'],
                'customer_identity' => $snapshot['customer_identity'],
                'public_text_snapshot' => $snapshot['public_text_snapshot'],
                'selection_payload' => $snapshot['selection_payload'],
            ]);

            $this->createConversionPlans($locked, $acceptanceSnapshot, $snapshot['selected_lines'], $actor);

            SalesActivity::query()->create([
                'opportunity_id' => $opportunity->id,
                'actor_id' => $actor?->id,
                'type' => 'quote_accepted',
                'direction' => 'inbound',
                'subject' => 'Quote accepted',
                'body' => $data['name'].' accepted quote '.$locked->quote->quote_key.' v'.$locked->version_number.' for '.number_format($acceptedTotalExVat, 2, '.', ' ').' NOK ex VAT / '.number_format($acceptedTotalIncVat, 2, '.', ' ').' NOK inc VAT.',
                'metadata' => [
                    'quote_version_id' => $locked->id,
                    'method' => $data['method'] ?? 'public_link',
                    'ticket_message_id' => $data['ticket_message_id'] ?? null,
                    'acceptance_snapshot_id' => $acceptanceSnapshot->id,
                    'selected_line_ids' => $snapshot['selected_line_ids'],
                    'total_ex_vat' => $acceptedTotalExVat,
                ],
            ]);

            $context = $opportunity->ticketSalesContext()->with('ticket')->first();
            if ($context?->ticket) {
                $ticket = $context->ticket;
                $selectedLineIds = collect($snapshot['selected_line_ids'])->map(fn ($id): int => (int) $id);
                $plannedLineIds = $locked->lines
                    ->filter(fn ($line): bool => $selectedLineIds->contains((int) $line->id))
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
                    'fingerprint' => hash('sha256', implode('|', [$locked->id, $locked->version_number, $acceptedTotalExVat, $acceptedAt->timestamp])),
                    'subject_name' => $data['name'],
                    'evidenced_at' => $acceptedAt,
                    'created_by' => $actor?->id,
                    'metadata' => [
                        'method' => $data['method'] ?? 'public_link',
                        'acceptance_snapshot_id' => $acceptanceSnapshot->id,
                        'selected_line_ids' => $snapshot['selected_line_ids'],
                    ],
                ]);

                app(InvalidateTicketWorkflowReviews::class)->handle($ticket, 'The customer acceptance and approved scope changed.', $actor);

                TicketEvent::query()->create([
                    'ticket_id' => $ticket->id,
                    'actor_id' => $actor?->id,
                    'type' => 'sales_quote_accepted',
                    'message' => 'Customer accepted '.$locked->quote->quote_key.' v'.$locked->version_number.'.',
                    'after' => [
                        'quote_version_id' => $locked->id,
                        'acceptance_snapshot_id' => $acceptanceSnapshot->id,
                        'total_ex_vat' => $acceptedTotalExVat,
                        'method' => $data['method'] ?? 'public_link',
                    ],
                ]);
            }

            return $locked->refresh();
        });

        $this->processTicketQuoteDelivery($accepted, $actor);

        return $accepted->refresh();
    }

    private function processTicketQuoteDelivery(SalesQuoteVersion $version, ?User $actor): void
    {
        $version->loadMissing('quote.opportunity.ticketSalesContext.ticket');
        $ticket = $version->quote?->opportunity?->ticketSalesContext?->ticket;
        if (! $ticket) {
            return;
        }

        try {
            $this->ticketQuoteProcessor->handle($ticket, $version, $actor);
        } catch (Throwable $exception) {
            Log::error('Accepted Ticket quote delivery automation could not start.', [
                'ticket_id' => $ticket->id,
                'quote_version_id' => $version->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'accepted_quote_auto_process_failed',
                'message' => 'Accepted quote delivery automation could not start.',
                'after' => [
                    'quote_version_id' => $version->id,
                    'reason' => $exception->getMessage() ?: 'Unknown processing error.',
                ],
            ]);
        }
    }

    private function createConversionPlans(SalesQuoteVersion $version, SalesQuoteAcceptanceSnapshot $snapshot, array $selectedLines, ?User $actor): void
    {
        foreach ($selectedLines as $line) {
            [$targetDomain, $targetType, $status] = $this->targetForDownstreamType($line['downstream_type'] ?? null);

            SalesQuoteConversionPlan::query()->create([
                'quote_version_id' => $version->id,
                'acceptance_snapshot_id' => $snapshot->id,
                'quote_line_id' => $line['id'] ?? null,
                'target_domain' => $targetDomain,
                'target_type' => $targetType,
                'status' => $status,
                'idempotency_key' => hash('sha256', implode('|', [
                    'sales-cpq',
                    $version->id,
                    $snapshot->id,
                    $line['id'] ?? 'line',
                    $targetDomain,
                    $targetType,
                ])),
                'source_snapshot' => $line['source_snapshot'] ?? null,
                'accepted_line_snapshot' => $line,
                'created_by' => $actor?->id,
            ]);
        }
    }

    private function targetForDownstreamType(?string $downstreamType): array
    {
        return match ($downstreamType) {
            'recurring_contract' => ['Commercial', 'contract_line', 'pending'],
            'one_time_order' => ['Economy', 'order_line', 'pending'],
            'equipment' => ['Storage', 'reservation_or_procurement', 'pending'],
            'implementation' => ['Ticket', 'implementation_scope', 'pending'],
            'non_billable' => ['Sales', 'no_conversion', 'not_applicable'],
            default => ['Sales', $downstreamType ?: 'manual_review', 'pending_review'],
        };
    }
}
