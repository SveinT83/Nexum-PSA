<?php

namespace App\Modules\Clients\Controllers\Api\V1;

use App\Models\Clients\Client;
use App\Modules\Clients\Actions\ExecuteClientCommand;
use App\Modules\Clients\Support\ClientInput;
use App\Modules\DataExchange\Services\TripletexCustomerLinks;
use App\Modules\DataExchange\Services\TripletexCustomerNumbers;
use App\Modules\Integration\Exceptions\TripletexException;
use App\Modules\Integration\Support\TripletexAccess;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Apply a reviewed identity pairing to the existing Client without creating a provider customer. */
final class TripletexMergeController
{
    public function __invoke(Request $request, Client $client, TripletexCustomerNumbers $numbers, TripletexCustomerLinks $links): JsonResponse
    {
        $authorize = fn () => app(TripletexAccess::class)->authorize($request->user());
        $authorize();
        ClientInput::allowed($request, ['customer_id', 'connection_id', 'expected_local', 'expected_remote', 'accept_change']);
        $input = $request->validate([
            'connection_id' => ['required', 'uuid'],
            'customer_id' => ['required', 'integer', 'min:1'],
            'expected_local' => ['present', 'nullable', 'string', 'max:255'],
            'expected_remote' => ['required', 'string', 'regex:/^[1-9][0-9]{0,9}$/'],
            'accept_change' => ['required', 'accepted'],
        ]);
        $connection = $numbers->connection();
        abort_unless($connection && $connection->id === $input['connection_id'], 409, 'The reviewed Tripletex connection changed. Review both customers again.');

        $result = app(ExecuteClientCommand::class)->handle($request, $authorize, function () use ($links, $connection, $client, $input) {
            try {
                $links->adopt($connection, $client, (int) $input['customer_id'], (string) $input['expected_local'], $input['expected_remote'], true, requireActive: true);
            } catch (TripletexException) {
                abort(503, 'The selected Tripletex customer could not be verified.');
            } catch (LockTimeoutException) {
                abort(409, 'Customer synchronization is busy. Read the current link before retrying.');
            }

            return ['state' => 'linked', 'client_id' => $client->id, 'tripletex_customer_id' => (int) $input['customer_id'], 'client_number' => $input['expected_remote']];
        }, required: true, external: true);

        return response()->json($result, isset($result['client_id']) ? 200 : 202)->header('Cache-Control', 'private, no-store');
    }
}
