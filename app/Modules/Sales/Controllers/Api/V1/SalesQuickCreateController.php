<?php

namespace App\Modules\Sales\Controllers\Api\V1;

use App\Models\Clients\Client;
use App\Modules\Sales\Actions\ExecuteSalesApiCommand;
use App\Modules\Sales\Actions\SalesQuickCreate;
use App\Modules\Sales\Models\SalesApiCommandReceipt;
use App\Modules\Sales\Support\SalesApiAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Provider creation keeps its intent outside SQL rollback, as required by the Client authority. */
class SalesQuickCreateController extends SalesApiController
{
    public function client(Request $request, SalesQuickCreate $create)
    {
        $data = $create->clientData($request);
        $connection = app(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class)->connection();
        if (! $connection) {
            $receipt = $this->command($request, null, fn () => ['type' => 'client', 'id' => $create->client($request)['client']->id]);
        } else {
            $request->validate(['tripletex_request_key' => ['required', 'uuid'], 'tripletex_number_mode' => ['required', 'accepted']]);
            abort_if(! empty($data['client_number']), 422, 'Omit client_number in provider-authoritative mode.');
            $key = $request->header('Idempotency-Key');
            abort_unless(is_string($key) && strlen($key) >= 8 && strlen($key) <= 200, 422, 'Idempotency-Key is required.');
            $actorKey = SalesApiAccess::actorKey($request);
            $keyHash = hash('sha256', $key);
            $hash = hash('sha256', json_encode(app(ExecuteSalesApiCommand::class)->stable($data), JSON_THROW_ON_ERROR));
            $receipt = Cache::lock('sales-client:'.$actorKey.':'.$keyHash, 600)->block(10, function () use ($request, $create, $connection, $actorKey, $keyHash, $hash, $data) {
                SalesApiAccess::authorize($request);
                $record = SalesApiCommandReceipt::query()->where('actor_key', $actorKey)->where('operation', 'sales.client.provider')->where('key_hash', $keyHash)->first();
                $recover = (bool) $record;
                abort_if($record && ! hash_equals($record->request_hash, $hash), 409, 'The key was used with different input.');
                if (($record?->result['type'] ?? '') === 'client') {
                    return $record->result + ['operation_id' => $record->id, 'replayed' => true];
                }
                $record ??= SalesApiCommandReceipt::query()->create(['actor_key' => $actorKey, 'operation' => 'sales.client.provider', 'key_hash' => $keyHash, 'request_hash' => $hash, 'result' => ['type' => 'provider_intent', 'state' => 'pending'], 'created_at' => now()]);
                $providerKey = hash_hmac('sha256', (string) auth()->id().':'.$data['tripletex_request_key'], config('app.key'));
                $link = $recover ? \App\Modules\DataExchange\Models\TripletexCustomerLink::query()->where('connection_id', $connection->id)->where('request_key', $providerKey)->first() : null;
                // Only a prior identical durable intent can recover its already-created Client.
                $client = $link?->client_id ? Client::findOrFail($link->client_id) : $create->client($request)['client'];
                $record->forceFill(['result' => ['type' => 'client', 'id' => $client->id]])->save();

                return $record->result + ['operation_id' => $record->id, 'replayed' => $recover];
            });
        }
        $client = Client::findOrFail($receipt['id']);

        return response()->json(['data' => $client->only(['id', 'name', 'client_number']) + ['sites' => $client->sites()->get(['id', 'name']), 'contacts' => $client->contacts()->get(['client_users.id', 'contact_id', 'client_site_id', 'client_users.name', 'email', 'phone', 'role'])], 'operation' => $receipt], 201);
    }

    public function contact(Request $request, Client $client, SalesQuickCreate $create)
    {
        $receipt = $this->command($request, null, function () use ($request, $client, $create) {
            $result = $create->contact($request, $client);

            return ['type' => 'contact', 'id' => $result['contact']['id'], 'site_id' => $result['site']['id']];
        });
        $contact = $client->contacts()->where('client_users.id', $receipt['id'])->firstOrFail();

        return response()->json(['data' => ['contact' => $contact->only(['id', 'contact_id', 'client_site_id', 'name', 'email', 'phone', 'role']), 'site' => $client->sites()->findOrFail($receipt['site_id'])->only(['id', 'name'])], 'operation' => $receipt], 201);
    }
}
