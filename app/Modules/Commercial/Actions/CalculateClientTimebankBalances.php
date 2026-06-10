<?php

namespace App\Modules\Commercial\Actions;

use App\Models\Clients\Client;
use App\Modules\Commercial\Models\Contracts\ClientContractTimeConsumption;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\ContractItemTimeRate;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Ticket\Models\TicketTimeEntry;
use App\Modules\Ticket\Models\TicketTimeEntryAllocation;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class CalculateClientTimebankBalances
{
    public function forClient(Client $client, ?CarbonInterface $workDate = null): Collection
    {
        $workDate = Carbon::parse($workDate ?? now())->startOfDay();

        $contracts = $client->contracts()
            ->with(['items.service'])
            ->whereIn('approval_status', ['approved', 'won'])
            ->whereDate('start_date', '<=', $workDate->toDateString())
            ->where(function ($query) use ($workDate): void {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $workDate->toDateString());
            })
            ->latest('updated_at')
            ->get();

        return $contracts
            ->flatMap(fn (Contracts $contract) => $contract->items
                ->filter(fn (ContractItem $item): bool => (bool) $item->service?->timebank_enabled)
                ->map(fn (ContractItem $item): array => $this->forContractItem($client, $contract, $item, $workDate)))
            ->values();
    }

    public function forContractItem(Client $client, Contracts $contract, ContractItem $item, ?CarbonInterface $workDate = null): array
    {
        $workDate = Carbon::parse($workDate ?? now())->startOfDay();
        [$periodStart, $periodEnd] = $this->timebankPeriod($workDate, $contract, $item);
        $includedMinutes = $this->includedMinutesForContractItem($item);
        $allocatedMinutes = $this->allocatedTicketMinutes($item, $periodStart, $periodEnd);
        $pendingMinutes = $this->pendingTicketMinutes($item, $periodStart, $periodEnd);
        $quickMinutes = $this->quickConsumptionMinutes($item, $periodStart, $periodEnd);
        $usedMinutes = $allocatedMinutes + $pendingMinutes + $quickMinutes;
        $remainingMinutes = max(0, $includedMinutes - $usedMinutes);
        $overusedMinutes = max(0, $usedMinutes - $includedMinutes);

        return [
            'client' => $client,
            'contract' => $contract,
            'contract_item' => $item,
            'service' => $item->service,
            'period_start' => $periodStart->copy(),
            'period_end' => $periodEnd->copy(),
            'included_minutes' => $includedMinutes,
            'used_minutes' => $usedMinutes,
            'allocated_ticket_minutes' => $allocatedMinutes,
            'pending_ticket_minutes' => $pendingMinutes,
            'quick_minutes' => $quickMinutes,
            'remaining_minutes' => $remainingMinutes,
            'overused_minutes' => $overusedMinutes,
            'usage_percent' => $includedMinutes > 0 ? min(100, (int) round(($usedMinutes / $includedMinutes) * 100)) : 0,
            'overuse_percent' => $includedMinutes > 0 ? min(100, (int) round(($overusedMinutes / $includedMinutes) * 100)) : 0,
            'recent_consumptions' => ClientContractTimeConsumption::query()
                ->with('user')
                ->where('contract_item_id', $item->id)
                ->whereDate('period_start', $periodStart->toDateString())
                ->whereDate('period_end', $periodEnd->toDateString())
                ->latest('work_date')
                ->latest('id')
                ->limit(5)
                ->get(),
            'time_rate_options' => $this->timeRateOptions($item),
        ];
    }

    public function timeRateOptions(ContractItem $item): Collection
    {
        $contractRates = $item->timeRates()
            ->where('is_active', true)
            ->where('amount_ex_vat', '>', 0)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ContractItemTimeRate $rate): array => [
                'value' => 'contract:'.$rate->id,
                'label' => $rate->name.' - '.number_format((float) $rate->amount_ex_vat, 2, '.', ' ').' '.$rate->currency.'/h',
                'source' => 'contract',
                'id' => $rate->id,
            ]);

        if ($contractRates->isNotEmpty()) {
            return $contractRates->values();
        }

        return TimeRate::query()
            ->where('is_active', true)
            ->where('applies_with_contract', true)
            ->where('amount_ex_vat', '>', 0)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (TimeRate $rate): array => [
                'value' => 'global:'.$rate->id,
                'label' => $rate->name.' - '.number_format((float) $rate->amount_ex_vat, 2, '.', ' ').' '.$rate->currency.'/h',
                'source' => 'global',
                'id' => $rate->id,
            ])
            ->values();
    }

    public function timebankPeriod(CarbonInterface $workDate, Contracts $contract, ContractItem $item): array
    {
        $contractStart = Carbon::parse($contract->start_date)->startOfDay();
        $contractEnd = $contract->end_date ? Carbon::parse($contract->end_date)->endOfDay() : null;

        return match ($item->service?->timebank_interval) {
            'yearly' => $this->yearlyTimebankPeriod($workDate, $contractStart, $contractEnd),
            'one_time' => [
                $contractStart->copy(),
                $contractEnd?->copy() ?? Carbon::parse('2099-12-31')->endOfDay(),
            ],
            default => [
                Carbon::parse($workDate)->startOfMonth(),
                Carbon::parse($workDate)->endOfMonth(),
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

    private function includedMinutesForContractItem(ContractItem $item): int
    {
        return max(0, (int) round((float) $item->service?->timebank_minutes * max(1, (int) $item->quantity)));
    }

    private function allocatedTicketMinutes(ContractItem $item, CarbonInterface $periodStart, CarbonInterface $periodEnd): int
    {
        return (int) TicketTimeEntryAllocation::query()
            ->where('contract_item_id', $item->id)
            ->whereDate('period_start', $periodStart->toDateString())
            ->whereDate('period_end', $periodEnd->toDateString())
            ->selectRaw('COALESCE(SUM(covered_minutes + billable_minutes), 0) as minutes')
            ->value('minutes');
    }

    private function pendingTicketMinutes(ContractItem $item, CarbonInterface $periodStart, CarbonInterface $periodEnd): int
    {
        return (int) TicketTimeEntry::query()
            ->where('contract_item_id', $item->id)
            ->whereDoesntHave('allocation')
            ->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->sum('minutes');
    }

    private function quickConsumptionMinutes(ContractItem $item, CarbonInterface $periodStart, CarbonInterface $periodEnd): int
    {
        return (int) ClientContractTimeConsumption::query()
            ->where('contract_item_id', $item->id)
            ->whereDate('period_start', $periodStart->toDateString())
            ->whereDate('period_end', $periodEnd->toDateString())
            ->sum('minutes');
    }
}
