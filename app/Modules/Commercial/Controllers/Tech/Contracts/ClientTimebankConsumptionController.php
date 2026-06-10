<?php

namespace App\Modules\Commercial\Controllers\Tech\Contracts;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Modules\Commercial\Actions\RegisterClientTimebankConsumption;
use App\Modules\Commercial\Support\ClientTimebankQuickPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientTimebankConsumptionController extends Controller
{
    public function store(Client $client, Request $request, RegisterClientTimebankConsumption $register, ClientTimebankQuickPolicy $policy): RedirectResponse
    {
        $settings = $policy->get();

        $data = $request->validate([
            'contract_item_id' => ['required', 'integer', Rule::exists('contract_items', 'id')],
            'time_rate_source' => ['required', 'string', 'max:50'],
            'work_date' => ['required', 'date'],
            'minutes' => ['required', 'integer', 'min:1', 'max:'.$settings['quick_timebank_max_minutes']],
            'note' => [$settings['quick_timebank_require_note'] ? 'required' : 'nullable', 'string', 'max:2000'],
        ]);

        $register->handle($client, $request->user(), $data);

        return redirect()
            ->route('tech.clients.show', ['client' => $client->id, 'tab' => 'contracts'])
            ->with('status', 'Timebank usage registered.');
    }
}
