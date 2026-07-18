<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Models\Ticket;
use Illuminate\Validation\ValidationException;

class TicketApprovedScopeGuard
{
    public function assertWithinApprovedScope(Ticket $ticket): void
    {
        $ticket->loadMissing('salesContext.opportunity.currentQuoteVersion');
        $version = $ticket->salesContext?->opportunity?->currentQuoteVersion;

        if (! $version || $version->status !== 'accepted') {
            return;
        }

        $approved = round((float) $version->total_ex_vat, 2);
        $actualCosts = (float) $ticket->costEntries()
            ->whereNotIn('status', ['cancelled', 'released'])
            ->selectRaw('COALESCE(SUM(quantity * unit_price_ex_vat), 0) AS total')
            ->value('total');
        $actualTime = $ticket->timeEntries()
            ->where('billable', true)
            ->whereNotIn('billing_status', ['cancelled', 'credited'])
            ->get()
            ->sum(fn ($entry) => ((float) $entry->minutes / 60) * (float) ($entry->rate_amount_ex_vat ?? 0));
        $actual = round($actualCosts + $actualTime, 2);

        $state = app(TicketWorkflowRuntime::class)->currentState($ticket);
        $tolerance = max(0, (float) data_get($state, 'commercial_policy.approved_scope_tolerance_ex_vat', 0));

        if ($actual > $approved + $tolerance) {
            throw ValidationException::withMessages([
                'approved_scope' => sprintf(
                    'Actual billable scope is %.2f NOK ex VAT, above the accepted quote of %.2f NOK plus %.2f NOK tolerance. Create and obtain acceptance for a revised quote before closing.',
                    $actual,
                    $approved,
                    $tolerance,
                ),
            ]);
        }
    }
}
