<?php

namespace App\Modules\Commercial\Actions;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Commercial\Models\Contracts\ClientContractTimeConsumption;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\ContractItemTimeRate;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Commercial\Support\ClientTimebankQuickPolicy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterClientTimebankConsumption
{
    public function __construct(
        private readonly CalculateClientTimebankBalances $balances,
        private readonly ClientTimebankQuickPolicy $policy,
    ) {
    }

    public function handle(Client $client, User $actor, array $data): ClientContractTimeConsumption
    {
        $settings = $this->policy->get();

        if (! $settings['quick_timebank_enabled']) {
            throw ValidationException::withMessages([
                'contract_item_id' => 'Quick timebank registration is disabled.',
            ]);
        }

        if (! $actor->can('commercial.timebank.quick-consume')) {
            throw ValidationException::withMessages([
                'contract_item_id' => 'You do not have permission to register quick timebank usage.',
            ]);
        }

        $minutes = (int) $data['minutes'];
        if ($minutes > $settings['quick_timebank_max_minutes']) {
            throw ValidationException::withMessages([
                'minutes' => 'Minutes exceeds the configured quick registration limit.',
            ]);
        }

        return DB::transaction(function () use ($client, $actor, $data, $minutes, $settings): ClientContractTimeConsumption {
            $item = ContractItem::query()
                ->with(['contract', 'service'])
                ->lockForUpdate()
                ->findOrFail((int) $data['contract_item_id']);

            if ((int) $item->contract?->client_id !== (int) $client->id || ! $item->service?->timebank_enabled) {
                throw ValidationException::withMessages([
                    'contract_item_id' => 'The selected contract line is not a timebank line for this client.',
                ]);
            }

            $workDate = Carbon::parse($data['work_date'])->startOfDay();

            if (! in_array($item->contract->approval_status, ['approved', 'won'], true)
                || $workDate->lt(Carbon::parse($item->contract->start_date)->startOfDay())
                || ($item->contract->end_date && $workDate->gt(Carbon::parse($item->contract->end_date)->endOfDay()))
            ) {
                throw ValidationException::withMessages([
                    'contract_item_id' => 'The selected contract line is not active for the work date.',
                ]);
            }

            $balance = $this->balances->forContractItem(
                $client,
                $item->contract,
                $item,
                $workDate,
            );

            $usedAfter = $balance['used_minutes'] + $minutes;
            $willOverconsume = $usedAfter > $balance['included_minutes'];
            $overusedBefore = max(0, $balance['used_minutes'] - $balance['included_minutes']);
            $overusedAfter = max(0, $usedAfter - $balance['included_minutes']);
            $overusedByEntry = max(0, $overusedAfter - $overusedBefore);

            if ($settings['quick_timebank_require_remaining'] && $balance['remaining_minutes'] <= 0) {
                throw ValidationException::withMessages([
                    'minutes' => 'No included time remains for this contract period.',
                ]);
            }

            if ($willOverconsume && (! $settings['quick_timebank_allow_overuse'] || ! $actor->can('commercial.timebank.overconsume'))) {
                throw ValidationException::withMessages([
                    'minutes' => 'This registration would overconsume the contract timebank.',
                ]);
            }

            $rate = $this->resolveRate($item, $data['time_rate_source'] ?? null);

            return ClientContractTimeConsumption::query()->create([
                'client_id' => $client->id,
                'contract_id' => $item->contract_id,
                'contract_item_id' => $item->id,
                'contract_item_time_rate_id' => $rate['contract_item_time_rate_id'],
                'time_rate_id' => $rate['time_rate_id'],
                'user_id' => $actor->id,
                'work_date' => $data['work_date'],
                'minutes' => $minutes,
                'note' => $data['note'] ?? null,
                'source' => 'quick_client',
                'rate_name' => $rate['rate_name'],
                'rate_code' => $rate['rate_code'],
                'rate_type' => $rate['rate_type'],
                'rate_unit' => $rate['rate_unit'],
                'rate_amount_ex_vat' => $rate['rate_amount_ex_vat'],
                'rate_currency' => $rate['rate_currency'],
                'period_start' => $balance['period_start']->toDateString(),
                'period_end' => $balance['period_end']->toDateString(),
                'included_minutes_snapshot' => $balance['included_minutes'],
                'used_before_minutes_snapshot' => $balance['used_minutes'],
                'overused_minutes' => $overusedByEntry,
            ]);
        });
    }

    private function resolveRate(ContractItem $item, ?string $source): array
    {
        if (! $source || ! str_contains($source, ':')) {
            throw ValidationException::withMessages([
                'time_rate_source' => 'Select a time rate.',
            ]);
        }

        [$type, $id] = explode(':', $source, 2);

        if ($type === 'contract') {
            $rate = ContractItemTimeRate::query()
                ->where('contract_item_id', $item->id)
                ->where('is_active', true)
                ->where('amount_ex_vat', '>', 0)
                ->find((int) $id);

            if (! $rate) {
                throw ValidationException::withMessages([
                    'time_rate_source' => 'The selected contract time rate is not available.',
                ]);
            }

            return [
                'contract_item_time_rate_id' => $rate->id,
                'time_rate_id' => $rate->time_rate_id,
                'rate_name' => $rate->name,
                'rate_code' => $rate->code,
                'rate_type' => $rate->rate_type,
                'rate_unit' => $rate->unit,
                'rate_amount_ex_vat' => (float) $rate->amount_ex_vat,
                'rate_currency' => $rate->currency ?: 'NOK',
            ];
        }

        if ($type === 'global') {
            $rate = TimeRate::query()
                ->where('is_active', true)
                ->where('applies_with_contract', true)
                ->where('amount_ex_vat', '>', 0)
                ->find((int) $id);

            if (! $rate) {
                throw ValidationException::withMessages([
                    'time_rate_source' => 'The selected time rate is not available.',
                ]);
            }

            return [
                'contract_item_time_rate_id' => null,
                'time_rate_id' => $rate->id,
                'rate_name' => $rate->name,
                'rate_code' => $rate->code,
                'rate_type' => $rate->rate_type,
                'rate_unit' => $rate->unit,
                'rate_amount_ex_vat' => (float) $rate->amount_ex_vat,
                'rate_currency' => $rate->currency ?: 'NOK',
            ];
        }

        throw ValidationException::withMessages([
            'time_rate_source' => 'Select a valid time rate.',
        ]);
    }
}
