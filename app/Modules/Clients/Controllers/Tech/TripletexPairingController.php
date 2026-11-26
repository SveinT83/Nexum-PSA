<?php

namespace App\Modules\Clients\Controllers\Tech;

use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use App\Modules\DataExchange\Services\TripletexCustomerLinks;
use App\Modules\DataExchange\Services\TripletexCustomerNumbers;
use App\Modules\Integration\Exceptions\TripletexException;
use App\Modules\Integration\Services\Tripletex\TripletexClient;
use App\Modules\Integration\Support\TripletexAccess;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Staff may review one selected customer pair without access to integration administration. */
final class TripletexPairingController
{
    public function review(Request $request, string $connection, TripletexCustomerNumbers $numbers): View
    {
        $active = $this->activeConnection($request, $connection, $numbers);
        $input = $request->validate(['client_id' => 'required|integer|min:1', 'customer_id' => 'required|integer|min:1']);
        $client = Client::findOrFail($input['client_id']);
        try {
            $remote = (new TripletexClient($active))->customer((int) $input['customer_id']);
            $numbers->assertUsable($remote);
        } catch (TripletexException) {
            abort(503, 'The selected Tripletex customer could not be verified.');
        }

        return view('clients::Tech.tripletex-review', ['review' => ['client' => $client, 'remote' => $remote],
            'action' => route('tech.clients.tripletex.link', $connection)]);
    }

    public function store(Request $request, string $connection, TripletexCustomerNumbers $numbers, TripletexCustomerLinks $links): RedirectResponse
    {
        $active = $this->activeConnection($request, $connection, $numbers);
        $input = $request->validate(['client_id' => 'required|integer|min:1', 'customer_id' => 'required|integer|min:1',
            'expected_local' => 'present|nullable|string|max:255', 'expected_remote' => ['required', 'string', 'regex:/^[1-9][0-9]{0,9}$/'],
            'accept_change' => 'required|accepted']);
        $client = Client::findOrFail($input['client_id']);
        try {
            $links->adopt($active, $client, (int) $input['customer_id'], (string) $input['expected_local'], $input['expected_remote'], true, requireActive: true);
        } catch (TripletexException) {
            abort(503, 'The selected Tripletex customer could not be verified.');
        } catch (LockTimeoutException) {
            abort(409, 'Customer synchronization is busy. Read the current link before retrying.');
        }

        return redirect()->route('tech.clients.show', $client)->with('status', 'Tripletex customer linked.');
    }

    private function activeConnection(Request $request, string $connection, TripletexCustomerNumbers $numbers): Integration
    {
        $actor = $request->user();
        app(TripletexAccess::class)->authorize($actor);
        abort_unless($actor->can('client.view') && $actor->can('client.update'), 403);
        $active = $numbers->connection();
        abort_unless($active && $active->id === $connection, 409, 'The active Tripletex connection changed. Review both customers again.');

        return $active;
    }
}
