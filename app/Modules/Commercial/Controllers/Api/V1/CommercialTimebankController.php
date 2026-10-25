<?php

namespace App\Modules\Commercial\Controllers\Api\V1;

use App\Models\Clients\Client;
use App\Modules\Commercial\Actions\CalculateClientTimebankBalances;
use App\Modules\Commercial\Actions\RegisterClientTimebankConsumption;
use App\Modules\Commercial\Actions\TimebankPolicyWorkflow;
use App\Modules\Commercial\Models\Contracts\ClientContractTimeConsumption;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Support\ClientTimebankQuickPolicy;
use App\Modules\Commercial\Support\CommercialInput;
use App\Modules\Commercial\Support\TimebankRevision;
use Illuminate\Http\Request;

/** Operational staff timebank API remains distinct from workload-bound pseudonymous history export. */
class CommercialTimebankController extends CommercialApiController
{
    private function client(Request $request): Client
    {
        return Client::query()->findOrFail($request->route('client'));
    }

    public function context(Request $request)
    {
        $request->validate(['work_date' => 'sometimes|date']);
        $client = $this->client($request);
        $policy = app(ClientTimebankQuickPolicy::class)->get();
        $date = \Carbon\Carbon::parse($request->input('work_date', today()->toDateString()));
        $rows = app(CalculateClientTimebankBalances::class)->forClient($client, $date)->map(function ($b) use ($policy) {
            return [
                'contract_id' => $b['contract']->id, 'contract_item_id' => $b['contract_item']->id, 'service_id' => $b['service']->id,
                'period_start' => $b['period_start']->toDateString(), 'period_end' => $b['period_end']->toDateString(),
                'included_minutes' => $b['included_minutes'], 'used_minutes' => $b['used_minutes'], 'remaining_minutes' => $b['remaining_minutes'], 'overused_minutes' => $b['overused_minutes'],
                'allocated_ticket_minutes' => $b['allocated_ticket_minutes'], 'pending_ticket_minutes' => $b['pending_ticket_minutes'], 'quick_minutes' => $b['quick_minutes'],
                'time_rate_options' => $b['time_rate_options']->all(), 'revision' => TimebankRevision::of($b, $policy),
                'recent_consumptions' => $b['recent_consumptions']->map(fn ($e) => $this->entry($e))->all(),
            ];
        });

        return response()->json(['data' => ['client_id' => $client->id, 'work_date' => $date->toDateString(), 'policy' => $policy, 'balances' => $rows]]);
    }

    private function entry(ClientContractTimeConsumption $row): array
    {
        return $row->only(['id', 'client_id', 'contract_id', 'contract_item_id', 'contract_item_time_rate_id', 'time_rate_id', 'user_id', 'work_date', 'minutes', 'note', 'source', 'rate_name', 'rate_code', 'rate_type', 'rate_unit', 'rate_amount_ex_vat', 'rate_currency', 'period_start', 'period_end', 'included_minutes_snapshot', 'used_before_minutes_snapshot', 'overused_minutes']);
    }

    public function show(Request $request)
    {
        $client = $this->client($request);
        $row = ClientContractTimeConsumption::query()->where('client_id', $client->id)->findOrFail($request->route('consumption'));

        return response()->json(['data' => $this->entry($row)]);
    }

    public function store(Request $request)
    {
        $client = $this->client($request);
        $policy = app(ClientTimebankQuickPolicy::class)->get();
        $data = CommercialInput::validate($request, ['contract_item_id' => 'required|integer|exists:contract_items,id', 'time_rate_source' => 'required|string|max:50', 'work_date' => 'required|date', 'minutes' => 'required|integer|min:1|max:'.$policy['quick_timebank_max_minutes'], 'note' => ($policy['quick_timebank_require_note'] ? 'required' : 'nullable').'|string|max:2000']);
        $item = ContractItem::query()->with('contract')->findOrFail($data['contract_item_id']);
        abort_unless((int) $item->contract?->client_id === (int) $client->id, 404);
        $result = $this->command($request, $item->contract, function () use ($request, $client, $data) {
            $row = app(RegisterClientTimebankConsumption::class)->handle($client, $request->user(), $data, trim($request->header('If-Match'), '"'));

            return ['data' => $this->entry($row)];
        }, true, fn () => abort_unless($request->header('If-Match'), 428, 'Read the balance revision and supply If-Match.'));

        return response()->json($result, 201);
    }

    public function policy(Request $request)
    {
        $policy = app(ClientTimebankQuickPolicy::class)->get();

        return response()->json(['data' => $policy, 'revision' => TimebankPolicyWorkflow::revision($policy)]);
    }

    public function updatePolicy(Request $request)
    {
        return response()->json($this->command($request, null, function () use ($request) {
            $policy = app(TimebankPolicyWorkflow::class)->save($request);

            return ['data' => $policy, 'revision' => TimebankPolicyWorkflow::revision($policy)];
        }));
    }

    public function settings(Request $request)
    {
        return response()->json(['data' => ['contracts' => url('/api/v1/commercial/contracts/context'), 'services' => url('/api/v1/commercial/services/context'), 'timebank_policy' => url('/api/v1/commercial/settings/timebank-policy'), 'units' => url('/api/v1/commercial/units/context'), 'time_rates' => url('/api/v1/commercial/time-rates/context'), 'economy_owner' => 'Economy']]);
    }
}
