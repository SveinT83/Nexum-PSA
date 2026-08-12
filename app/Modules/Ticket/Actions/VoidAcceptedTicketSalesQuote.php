<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderLine;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketPlannedLine;
use App\Modules\Ticket\Models\TicketWorkflowEvidence;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoidAcceptedTicketSalesQuote
{
    public function __construct(
        private readonly TicketActionGuard $guard,
        private readonly ReleaseTicketStorageReservation $releaseReservation,
    ) {}

    public function handle(Ticket $ticket, SalesQuoteVersion $version, array $data, User $actor): SalesQuoteVersion
    {
        if ($reason = $this->guard->reason($ticket, TicketAction::VOID_ACCEPTED_QUOTE, $actor)) {
            throw ValidationException::withMessages(['quote' => $reason]);
        }

        $voidReason = trim((string) ($data['reason'] ?? ''));
        if ($voidReason === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required when voiding an accepted quote.']);
        }

        return DB::transaction(function () use ($ticket, $version, $actor, $voidReason): SalesQuoteVersion {
            $locked = SalesQuoteVersion::query()
                ->with(['quote.opportunity.ticketSalesContext', 'conversionPlans'])
                ->lockForUpdate()
                ->findOrFail($version->id);

            $opportunity = $locked->quote->opportunity;
            abort_unless((int) $opportunity->ticketSalesContext?->ticket_id === (int) $ticket->id, 404);

            if ($locked->status === 'voided') {
                return $locked;
            }

            if ($locked->status !== 'accepted') {
                throw ValidationException::withMessages(['quote' => 'Only an accepted Ticket quote can be voided.']);
            }

            $plannedLines = TicketPlannedLine::query()
                ->with([
                    'convertedCostEntry.reservation',
                    'purchaseOrderLine.purchaseOrder',
                    'purchaseOrderLine.shipmentLines',
                    'purchaseOrderLine.receiptLines',
                ])
                ->where('ticket_id', $ticket->id)
                ->where('approved_quote_version_id', $locked->id)
                ->lockForUpdate()
                ->get();

            $blockers = $this->voidBlockers($plannedLines);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['quote' => $blockers[0]]);
            }

            foreach ($plannedLines as $line) {
                $this->voidPlannedLine($ticket, $line, $actor, $voidReason);
            }

            $voidedAt = now();
            $locked->forceFill([
                'status' => 'voided',
                'updated_by' => $actor->id,
                'snapshots' => array_merge($locked->snapshots ?: [], [
                    'voided' => [
                        'at' => $voidedAt->toISOString(),
                        'by' => $actor->id,
                        'reason' => $voidReason,
                    ],
                ]),
            ])->save();

            $locked->conversionPlans()->update([
                'status' => 'voided',
                'operator_note' => 'Accepted Ticket quote voided: '.$voidReason,
                'processed_at' => $voidedAt,
                'processed_by' => $actor->id,
            ]);

            if ((int) $locked->quote->current_version_id === (int) $locked->id) {
                $locked->quote->forceFill([
                    'status' => 'voided',
                    'current_version_id' => null,
                ])->save();
            }

            $replacementWon = SalesQuoteVersion::query()
                ->whereHas('quote', fn ($query) => $query->where('opportunity_id', $opportunity->id))
                ->where('status', 'accepted')
                ->whereKeyNot($locked->id)
                ->latest('accepted_at')
                ->first();

            $opportunityUpdates = [];
            if ((int) $opportunity->current_quote_version_id === (int) $locked->id) {
                $opportunityUpdates['current_quote_version_id'] = null;
            }
            if ((int) $opportunity->won_quote_version_id === (int) $locked->id) {
                $opportunityUpdates['won_quote_version_id'] = $replacementWon?->id;
                if (! $replacementWon && $opportunity->status === 'won') {
                    $opportunityUpdates['status'] = 'qualified';
                    $opportunityUpdates['probability_percent'] = 30;
                    $opportunityUpdates['weighted_value_ex_vat'] = round((float) $opportunity->estimated_value_ex_vat * 0.3, 2);
                    $opportunityUpdates['won_at'] = null;
                }
            }
            if ($opportunityUpdates !== []) {
                $opportunity->forceFill($opportunityUpdates)->save();
            }

            TicketWorkflowEvidence::query()
                ->where('ticket_id', $ticket->id)
                ->where('evidence_type', 'quote_acceptance')
                ->where('scope_key', 'quote-version:'.$locked->id)
                ->whereNull('invalidated_at')
                ->update([
                    'invalidated_at' => $voidedAt,
                    'invalidated_by' => $actor->id,
                    'invalidation_reason' => 'Accepted quote voided: '.$voidReason,
                ]);

            SalesActivity::query()->create([
                'opportunity_id' => $opportunity->id,
                'actor_id' => $actor->id,
                'type' => 'quote_voided',
                'subject' => 'Accepted Ticket quote voided',
                'body' => 'Accepted quote '.$locked->quote->quote_key.' v'.$locked->version_number.' was voided: '.$voidReason,
                'metadata' => [
                    'ticket_id' => $ticket->id,
                    'quote_version_id' => $locked->id,
                    'reason' => $voidReason,
                ],
            ]);

            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor->id,
                'type' => 'sales_quote_voided',
                'message' => 'Accepted Sales quote voided.',
                'after' => [
                    'quote_version_id' => $locked->id,
                    'reason' => $voidReason,
                ],
            ]);

            app(InvalidateTicketWorkflowReviews::class)->handle($ticket, 'Accepted customer quote was voided.', $actor);

            return $locked->refresh();
        });
    }

    /**
     * @param Collection<int, TicketPlannedLine> $plannedLines
     * @return list<string>
     */
    private function voidBlockers(Collection $plannedLines): array
    {
        $blockers = [];

        foreach ($plannedLines as $line) {
            $costEntry = $line->convertedCostEntry;
            if ($costEntry && ! $this->costCanBeVoided($costEntry)) {
                $blockers[] = 'Quote line "'.$line->name.'" has already moved beyond pending internal cost handling.';
            }

            $purchaseLine = $line->purchaseOrderLine;
            if ($purchaseLine && ! $this->purchaseNeedCanBeVoided($purchaseLine)) {
                $blockers[] = 'Quote line "'.$line->name.'" has a purchase need that is no longer a draft.';
            }
        }

        return $blockers;
    }

    private function costCanBeVoided(TicketCostEntry $entry): bool
    {
        if ($entry->billing_status !== 'pending') {
            return false;
        }

        if ($entry->storage_item_id) {
            return $entry->status === 'reserved'
                && (! $entry->reservation || $entry->reservation->status === 'active');
        }

        return $entry->status === 'manual';
    }

    private function purchaseNeedCanBeVoided(PurchaseOrderLine $line): bool
    {
        $order = $line->purchaseOrder;

        return $order?->status === PurchaseOrder::STATUS_DRAFT
            && (int) $line->qty_received === 0
            && (int) $line->qty_cancelled === 0
            && ! $line->shipmentLines()->exists()
            && ! $line->receiptLines()->exists();
    }

    private function voidPlannedLine(Ticket $ticket, TicketPlannedLine $line, User $actor, string $reason): void
    {
        if ($line->convertedCostEntry) {
            if ($line->convertedCostEntry->storage_item_id) {
                $this->releaseReservation->handle($ticket, $line->convertedCostEntry, $actor);
                $line = $line->refresh();
            } else {
                $line->convertedCostEntry->forceFill([
                    'status' => 'cancelled',
                    'billing_status' => 'cancelled',
                ])->save();
            }
        }

        if ($line->purchaseOrderLine) {
            $this->voidDraftPurchaseNeed($line->purchaseOrderLine, $actor, $reason);
        }

        $metadata = $line->metadata ?: [];
        $metadata['accepted_quote_voided'] = [
            'at' => now()->toISOString(),
            'by' => $actor->id,
            'reason' => $reason,
        ];

        $line->forceFill([
            'status' => 'cancelled',
            'metadata' => $metadata,
            'updated_by' => $actor->id,
        ])->save();
    }

    private function voidDraftPurchaseNeed(PurchaseOrderLine $line, User $actor, string $reason): void
    {
        $line->forceFill([
            'qty_cancelled' => $line->qty_ordered,
            'cancellation_reason' => 'Accepted Ticket quote voided: '.$reason,
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
            'updated_by' => $actor->id,
        ])->save();

        $order = $line->purchaseOrder;
        if (! $order) {
            return;
        }

        $allLinesCancelled = $order->lines()
            ->get(['qty_ordered', 'qty_cancelled'])
            ->every(fn (PurchaseOrderLine $orderLine): bool => (int) $orderLine->qty_cancelled >= (int) $orderLine->qty_ordered);

        if ($allLinesCancelled) {
            $order->forceFill([
                'status' => PurchaseOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'status_changed_at' => now(),
                'status_changed_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();
        }
    }
}
