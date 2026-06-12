<?php

namespace App\Modules\Economy\Actions;

use App\Models\Core\User;
use App\Modules\Commercial\Models\Contracts\ClientContractTimeConsumption;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketTimeEntry;
use App\Modules\Ticket\Models\TicketTimeEntryAllocation;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GenerateOrders
{
    public function __construct(private readonly EnsureEconomyDefaults $defaults)
    {
    }

    public function handle(?CarbonInterface $periodStart = null, ?CarbonInterface $periodEnd = null, ?User $actor = null): array
    {
        $settings = $this->defaults->handle();
        $periodStart = ($periodStart ? Carbon::parse($periodStart) : now()->startOfMonth())->startOfDay();
        $periodEnd = ($periodEnd ? Carbon::parse($periodEnd) : now()->endOfMonth())->endOfDay();

        return DB::transaction(function () use ($settings, $periodStart, $periodEnd, $actor): array {
            $summary = [
                'orders_touched' => 0,
                'lines_created' => 0,
                'time_entries_seen' => 0,
                'time_entries_ordered' => 0,
                'time_entries_waiting_for_contract' => 0,
                'cost_entries_seen' => 0,
                'cost_entries_ordered' => 0,
                'quick_timebank_entries_seen' => 0,
                'quick_timebank_entries_ordered' => 0,
            ];

            if ($settings->create_orders_from_closed_ticket_time || $settings->create_orders_from_resolved_ticket_time || $settings->include_unresolved_ticket_time_in_period_close) {
                $this->generateTimeLines($periodStart, $periodEnd, $actor, $settings, $summary);
            }

            if ($settings->create_orders_from_picked_ticket_costs) {
                $this->generateCostLines($periodStart, $periodEnd, $actor, $settings, $summary);
            }

            $this->generateQuickTimebankLines($periodStart, $periodEnd, $actor, $settings, $summary);

            return $summary;
        });
    }

    private function generateTimeLines(CarbonInterface $periodStart, CarbonInterface $periodEnd, ?User $actor, $settings, array &$summary): void
    {
        $query = TicketTimeEntry::query()
            ->with(['ticket.status', 'ticket.client'])
            ->where('billable', true)
            ->where('billing_status', 'pending')
            ->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->whereHas('ticket', fn ($query) => $query->whereNotNull('client_id'))
            ->orderBy('work_date')
            ->orderBy('created_at')
            ->orderBy('id');

        $query->get()->each(function (TicketTimeEntry $entry) use ($periodStart, $periodEnd, $actor, $settings, &$summary): void {
            $summary['time_entries_seen']++;

            if (! $this->timeEntryIsEligible($entry, $settings)) {
                return;
            }

            if ($entry->minutes <= 0) {
                return;
            }

            $entry = $this->attachContractTimebankIfAvailable($entry);
            $allocation = $this->allocateTimeEntry($entry, $periodStart, $periodEnd);

            if ($allocation->billable_minutes <= 0) {
                $entry->forceFill([
                    'billing_status' => 'covered',
                    'timebank_status' => 'covered',
                ])->save();
                return;
            }

            $rate = (float) ($entry->rate_amount_ex_vat ?? 0);
            if ($rate <= 0) {
                return;
            }

            $order = $this->draftOrder($entry->ticket->client_id, $periodStart, $periodEnd, $actor, $settings);
            $unitPrice = round($rate / 60, 4);
            $lineTotal = round($unitPrice * $allocation->billable_minutes, 2);
            $vatRate = $settings->default_vat_rate !== null ? (float) $settings->default_vat_rate : null;
            $vatAmount = $vatRate === null ? null : round($lineTotal * ($vatRate / 100), 2);

            $line = EconomyOrderLine::query()->firstOrCreate(
                [
                    'source_type' => $entry->getMorphClass(),
                    'source_id' => $entry->id,
                ],
                [
                    'economy_order_id' => $order->id,
                    'client_id' => $entry->ticket->client_id,
                    'ticket_id' => $entry->ticket_id,
                    'work_date' => $entry->work_date,
                    'line_type' => 'ticket_time',
                    'description' => $this->timeDescription($entry),
                    'quantity' => $allocation->billable_minutes,
                    'unit' => 'min',
                    'unit_price_ex_vat' => $unitPrice,
                    'line_total_ex_vat' => $lineTotal,
                    'vat_rate' => $vatRate,
                    'vat_amount' => $vatAmount,
                    'total_inc_vat' => $lineTotal + ($vatAmount ?? 0),
                    'currency' => $entry->rate_currency ?: 'NOK',
                    'metadata' => [
                        'ticket_key' => $entry->ticket?->ticket_key,
                        'rate_name' => $entry->rate_name,
                        'rate_amount_ex_vat_per_hour' => $rate,
                        'covered_minutes' => $allocation->covered_minutes,
                        'included_minutes' => $allocation->included_minutes,
                    ],
                ]
            );

            $allocation->forceFill([
                'economy_order_line_id' => $line->id,
                'status' => 'queued',
            ])->save();
            $entry->forceFill(['billing_status' => 'queued'])->save();
            $summary['time_entries_ordered']++;
            $summary['lines_created']++;
            $summary['orders_touched']++;
            $this->recalculate($order);
        });
    }

    private function attachContractTimebankIfAvailable(TicketTimeEntry $entry): TicketTimeEntry
    {
        if (! $entry->ticket?->client_id) {
            return $entry;
        }

        if ($entry->billing_basis === 'contract' && (float) ($entry->rate_amount_ex_vat ?? 0) > 0) {
            return $entry;
        }

        $workDate = Carbon::parse($entry->work_date ?? $entry->created_at ?? now())->toDateString();
        $contract = $entry->contract_id
            ? Contracts::query()->with(['items.service'])->find($entry->contract_id)
            : null;

        if ($contract && $entry->contract_item_id && $this->includedMinutesForContractItem($contract->items->firstWhere('id', $entry->contract_item_id)) <= 0) {
            $contract = null;
        }

        $contract ??= Contracts::query()
            ->with(['items.service'])
            ->where('client_id', $entry->ticket->client_id)
            ->where('approval_status', 'won')
            ->where(function ($query) use ($workDate) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $workDate);
            })
            ->where(function ($query) use ($workDate) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $workDate);
            })
            ->latest('accepted_at')
            ->latest('id')
            ->get()
            ->first(fn (Contracts $contract) => $contract->items->contains(fn (ContractItem $item) => $this->includedMinutesForContractItem($item) > 0));

        $contractItem = $contract?->items->first(fn (ContractItem $item) => $this->includedMinutesForContractItem($item) > 0);

        if (! $contract || ! $contractItem) {
            return $entry;
        }

        $rate = $this->contractRateForEntry($entry);

        $entry->forceFill([
            'billing_basis' => 'contract',
            'contract_id' => $contract->id,
            'contract_item_id' => $contractItem->id,
            'contract_item_time_rate_id' => null,
            'time_rate_id' => $rate?->id ?? $entry->time_rate_id,
            'rate_name' => $rate?->name ?? $entry->rate_name,
            'rate_code' => $rate?->code ?? $entry->rate_code,
            'rate_type' => $rate?->rate_type ?? $entry->rate_type,
            'rate_unit' => $rate?->unit ?? $entry->rate_unit,
            'rate_amount_ex_vat' => $rate?->amount_ex_vat ?? $entry->rate_amount_ex_vat,
            'rate_currency' => $rate?->currency ?? $entry->rate_currency,
        ])->save();

        return $entry->refresh()->loadMissing(['ticket.status', 'ticket.client']);
    }

    private function contractRateForEntry(TicketTimeEntry $entry): ?TimeRate
    {
        $currentRate = $entry->time_rate_id ? TimeRate::query()->find($entry->time_rate_id) : null;

        if ($currentRate?->applies_with_contract && (float) $currentRate->amount_ex_vat > 0) {
            return $currentRate;
        }

        $rateType = $entry->rate_type ?: $currentRate?->rate_type ?: 'labor';

        $query = TimeRate::query()
            ->where('is_active', true)
            ->where('applies_with_contract', true)
            ->where('amount_ex_vat', '>', 0);

        if ($rateType) {
            $query->where('rate_type', $rateType);
        }

        if ($rateType === 'labor') {
            $standardContractRate = (clone $query)->where('code', 'TIME_WITH_CONTRACT')->first();

            if ($standardContractRate) {
                return $standardContractRate;
            }
        }

        return $query->orderBy('sort_order')->orderBy('name')->first();
    }

    private function allocateTimeEntry(TicketTimeEntry $entry, CarbonInterface $periodStart, CarbonInterface $periodEnd): TicketTimeEntryAllocation
    {
        $existing = $entry->allocation()->first();

        if ($existing) {
            return $existing;
        }

        $includedMinutes = 0;
        $coveredMinutes = 0;
        $billableMinutes = $entry->minutes;
        $status = 'billable';
        $metadata = [
            'billing_basis' => $entry->billing_basis,
            'reason' => 'No included contract time applies.',
        ];

        if ($entry->billing_basis === 'contract' && $entry->contract_item_id) {
            $contractItem = ContractItem::query()
                ->with(['service', 'contract'])
                ->find($entry->contract_item_id);

            $includedMinutes = $this->includedMinutesForContractItem($contractItem);
            [$allocationPeriodStart, $allocationPeriodEnd] = $this->timebankPeriod($entry, $contractItem, $periodStart, $periodEnd);

            if ($includedMinutes > 0) {
                $alreadyCovered = TicketTimeEntryAllocation::query()
                    ->where('contract_item_id', $entry->contract_item_id)
                    ->whereDate('period_start', $allocationPeriodStart->toDateString())
                    ->whereDate('period_end', $allocationPeriodEnd->toDateString())
                    ->sum('covered_minutes');
                $remaining = max(0, $includedMinutes - (int) $alreadyCovered);
                $coveredMinutes = min($entry->minutes, $remaining);
                $billableMinutes = max(0, $entry->minutes - $coveredMinutes);
                $status = $billableMinutes > 0 ? ($coveredMinutes > 0 ? 'partially_billable' : 'billable') : 'covered';
                $metadata['reason'] = $billableMinutes > 0
                    ? 'Contract timebank did not cover the full entry.'
                    : 'Contract timebank covered the full entry.';
                $periodStart = $allocationPeriodStart;
                $periodEnd = $allocationPeriodEnd;
            }
        }

        return TicketTimeEntryAllocation::create([
            'ticket_time_entry_id' => $entry->id,
            'ticket_id' => $entry->ticket_id,
            'client_id' => $entry->ticket->client_id,
            'contract_id' => $entry->contract_id,
            'contract_item_id' => $entry->contract_item_id,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'included_minutes' => $includedMinutes,
            'covered_minutes' => $coveredMinutes,
            'billable_minutes' => $billableMinutes,
            'status' => $status,
            'metadata' => $metadata,
        ]);
    }

    private function includedMinutesForContractItem(?ContractItem $contractItem): int
    {
        if (! $contractItem?->service?->timebank_enabled) {
            return 0;
        }

        return max(0, (int) round((float) $contractItem->service->timebank_minutes * max(1, (int) $contractItem->quantity)));
    }

    private function timebankPeriod(TicketTimeEntry $entry, ?ContractItem $contractItem, CarbonInterface $fallbackStart, CarbonInterface $fallbackEnd): array
    {
        $workDate = Carbon::parse($entry->work_date ?? $entry->created_at ?? now())->startOfDay();
        $contractStart = $contractItem?->contract?->start_date
            ? Carbon::parse($contractItem->contract->start_date)->startOfDay()
            : $workDate->copy()->startOfMonth();
        $contractEnd = $contractItem?->contract?->end_date
            ? Carbon::parse($contractItem->contract->end_date)->endOfDay()
            : null;

        return match ($contractItem?->service?->timebank_interval) {
            'yearly' => $this->yearlyTimebankPeriod($workDate, $contractStart, $contractEnd),
            'one_time' => [
                $contractStart->copy(),
                $contractEnd?->copy() ?? Carbon::parse('2099-12-31')->endOfDay(),
            ],
            'monthly' => [
                $workDate->copy()->startOfMonth(),
                $workDate->copy()->endOfMonth(),
            ],
            default => [
                Carbon::parse($fallbackStart)->startOfDay(),
                Carbon::parse($fallbackEnd)->endOfDay(),
            ],
        };
    }

    private function yearlyTimebankPeriod(CarbonInterface $workDate, CarbonInterface $contractStart, ?CarbonInterface $contractEnd): array
    {
        $periodStart = $contractStart->copy();

        while ($periodStart->copy()->addYear()->lessThanOrEqualTo($workDate)) {
            $periodStart->addYear();
        }

        $periodEnd = $periodStart->copy()->addYear()->subDay()->endOfDay();

        if ($contractEnd && $contractEnd->lessThan($periodEnd)) {
            $periodEnd = $contractEnd->copy()->endOfDay();
        }

        return [$periodStart->startOfDay(), $periodEnd];
    }

    private function generateCostLines(CarbonInterface $periodStart, CarbonInterface $periodEnd, ?User $actor, $settings, array &$summary): void
    {
        TicketCostEntry::query()
            ->with(['ticket.client', 'storageItem'])
            ->where('status', 'picked')
            ->where('billing_status', 'pending')
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->whereHas('ticket', fn ($query) => $query->whereNotNull('client_id'))
            ->get()
            ->each(function (TicketCostEntry $entry) use ($periodStart, $periodEnd, $actor, $settings, &$summary): void {
                $summary['cost_entries_seen']++;

                $unitPrice = (float) ($entry->unit_price_ex_vat ?? 0);
                if ($unitPrice < 0 || $entry->quantity <= 0) {
                    return;
                }

                $order = $this->draftOrder($entry->ticket->client_id, $periodStart, $periodEnd, $actor, $settings);
                $lineTotal = round($unitPrice * $entry->quantity, 2);
                $vatRate = $entry->storageItem?->vat_rate !== null
                    ? (float) $entry->storageItem->vat_rate
                    : ($settings->default_vat_rate !== null ? (float) $settings->default_vat_rate : null);
                $vatAmount = $vatRate === null ? null : round($lineTotal * ($vatRate / 100), 2);

                EconomyOrderLine::query()->firstOrCreate(
                    [
                        'source_type' => $entry->getMorphClass(),
                        'source_id' => $entry->id,
                    ],
                    [
                        'economy_order_id' => $order->id,
                        'client_id' => $entry->ticket->client_id,
                        'ticket_id' => $entry->ticket_id,
                        'work_date' => $entry->created_at?->toDateString(),
                        'line_type' => 'ticket_cost',
                        'description' => $this->costDescription($entry),
                        'quantity' => $entry->quantity,
                        'unit' => 'pcs',
                        'unit_price_ex_vat' => $unitPrice,
                        'line_total_ex_vat' => $lineTotal,
                        'vat_rate' => $vatRate,
                        'vat_amount' => $vatAmount,
                        'total_inc_vat' => $lineTotal + ($vatAmount ?? 0),
                        'currency' => $entry->currency ?: 'NOK',
                        'metadata' => [
                            'ticket_key' => $entry->ticket?->ticket_key,
                            'item_name' => $entry->item_name,
                            'item_sku' => $entry->item_sku,
                        ],
                    ]
                );

                $entry->forceFill(['billing_status' => 'queued'])->save();
                $summary['cost_entries_ordered']++;
                $summary['lines_created']++;
                $summary['orders_touched']++;
                $this->recalculate($order);
            });
    }

    private function generateQuickTimebankLines(CarbonInterface $periodStart, CarbonInterface $periodEnd, ?User $actor, $settings, array &$summary): void
    {
        ClientContractTimeConsumption::query()
            ->with(['client', 'contract', 'contractItem'])
            ->where('overused_minutes', '>', 0)
            ->whereNotNull('rate_amount_ex_vat')
            ->where('rate_amount_ex_vat', '>', 0)
            ->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->orderBy('work_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (ClientContractTimeConsumption $entry) use ($periodStart, $periodEnd, $actor, $settings, &$summary): void {
                $summary['quick_timebank_entries_seen']++;

                $rate = (float) ($entry->rate_amount_ex_vat ?? 0);
                if ($entry->overused_minutes <= 0 || $rate <= 0) {
                    return;
                }

                $order = $this->draftOrder($entry->client_id, $periodStart, $periodEnd, $actor, $settings);
                $unitPrice = round($rate / 60, 4);
                $lineTotal = round($unitPrice * $entry->overused_minutes, 2);
                $vatRate = $settings->default_vat_rate !== null ? (float) $settings->default_vat_rate : null;
                $vatAmount = $vatRate === null ? null : round($lineTotal * ($vatRate / 100), 2);

                EconomyOrderLine::query()->firstOrCreate(
                    [
                        'source_type' => $entry->getMorphClass(),
                        'source_id' => $entry->id,
                    ],
                    [
                        'economy_order_id' => $order->id,
                        'client_id' => $entry->client_id,
                        'ticket_id' => null,
                        'work_date' => $entry->work_date,
                        'line_type' => 'quick_timebank_overuse',
                        'description' => $this->quickTimebankDescription($entry),
                        'quantity' => $entry->overused_minutes,
                        'unit' => 'min',
                        'unit_price_ex_vat' => $unitPrice,
                        'line_total_ex_vat' => $lineTotal,
                        'vat_rate' => $vatRate,
                        'vat_amount' => $vatAmount,
                        'total_inc_vat' => $lineTotal + ($vatAmount ?? 0),
                        'currency' => $entry->rate_currency ?: 'NOK',
                        'metadata' => [
                            'contract_id' => $entry->contract_id,
                            'contract_item_id' => $entry->contract_item_id,
                            'contract_item_name' => $entry->contractItem?->name,
                            'rate_name' => $entry->rate_name,
                            'rate_amount_ex_vat_per_hour' => $rate,
                            'included_minutes_snapshot' => $entry->included_minutes_snapshot,
                            'used_before_minutes_snapshot' => $entry->used_before_minutes_snapshot,
                            'registered_minutes' => $entry->minutes,
                            'overused_minutes' => $entry->overused_minutes,
                        ],
                    ]
                );

                $summary['quick_timebank_entries_ordered']++;
                $summary['lines_created']++;
                $summary['orders_touched']++;
                $this->recalculate($order);
            });
    }

    private function timeEntryIsEligible(TicketTimeEntry $entry, $settings): bool
    {
        if ($settings->include_unresolved_ticket_time_in_period_close) {
            return true;
        }

        if ($settings->create_orders_from_closed_ticket_time && (bool) $entry->ticket?->status?->is_closed) {
            return true;
        }

        return $settings->create_orders_from_resolved_ticket_time && filled($entry->ticket?->resolved_at);
    }

    private function draftOrder(int $clientId, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?User $actor, $settings): EconomyOrder
    {
        $order = EconomyOrder::query()->firstOrCreate(
            [
                'client_id' => $clientId,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'status' => 'draft',
            ],
            [
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
                'generated_at' => now(),
            ]
        );

        if (blank($order->order_number)) {
            $order->forceFill([
                'order_number' => $settings->order_prefix . str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
            ])->save();
        }

        return $order;
    }

    private function recalculate(EconomyOrder $order): void
    {
        $lines = $order->lines()->where('status', 'active')->get();

        $order->forceFill([
            'subtotal_ex_vat' => round((float) $lines->sum('line_total_ex_vat'), 2),
            'vat_amount' => round((float) $lines->sum(fn (EconomyOrderLine $line) => (float) ($line->vat_amount ?? 0)), 2),
            'total_inc_vat' => round((float) $lines->sum('total_inc_vat'), 2),
            'generated_at' => now(),
        ])->save();
    }

    private function timeDescription(TicketTimeEntry $entry): string
    {
        return trim(($entry->ticket?->ticket_key ? $entry->ticket->ticket_key . ' - ' : '') . ($entry->invoice_text ?: $entry->rate_name ?: 'Ticket time'));
    }

    private function costDescription(TicketCostEntry $entry): string
    {
        return trim(($entry->ticket?->ticket_key ? $entry->ticket->ticket_key . ' - ' : '') . ($entry->invoice_text ?: $entry->item_name));
    }

    private function quickTimebankDescription(ClientContractTimeConsumption $entry): string
    {
        $name = $entry->contractItem?->name ?: 'Contract timebank';
        $note = $entry->note ? ' - '.$entry->note : '';

        return trim('Overused timebank: '.$name.$note);
    }
}
