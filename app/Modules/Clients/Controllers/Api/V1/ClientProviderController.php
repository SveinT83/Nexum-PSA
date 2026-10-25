<?php

namespace App\Modules\Clients\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\System\Integrations\Integration;
use App\Modules\Clients\Actions\ExecuteClientCommand;
use App\Modules\Clients\Actions\ProvisionClientRmm;
use App\Modules\Clients\Support\ClientRevision;
use App\Modules\Integration\Support\DomainApiInput;
use App\Modules\Integration\Support\DomainApiRead;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use Illuminate\Http\Request;

/** Provider lookup is explicit and bounded; setup and credentials stay Integration-owned. */
class ClientProviderController extends Controller
{
    public function rmmLookup(Request $request)
    {
        $integration = Integration::query()->where('type', 'rmm')->where('status', 'active')->first();
        abort_unless($integration, 409, 'RMM is not active.');
        $data = app(NAbleRmmClient::class, ['integration' => $integration])->listClients();
        abort_if(isset($data['error']), 503, 'RMM lookup could not be verified.');

        return response()->json(DomainApiRead::paginateCollection(collect($data)->map(fn ($r) => ['id' => (string) ($r['clientid'] ?? ''), 'name' => (string) ($r['name'] ?? '')]), $request));
    }

    public function tripletex(Request $request)
    {
        abort_if($request->user()->isSystemActor(), 403, 'Human Client creation context is required.');
        $authority = app(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class);
        $connection = $authority->connection();
        abort_unless($connection, 409, 'Tripletex customer synchronization is paused.');
        $request->validate(['customer_id' => 'sometimes|integer|min:1']);
        $api = new \App\Modules\Integration\Services\Tripletex\TripletexClient($connection);
        try {
            if ($request->route('customer')) {
                $row = $api->customerProfile((int) $request->route('customer'));
                $authority->assertUsable($row);
                $data = app(\App\Modules\Integration\Services\Tripletex\CustomerProfileData::class)->prefill($row);
                if (! $request->user()->can('contact.view') || ! $request->user()->tokenCan('contacts.read')) {
                    foreach (['user_name', 'user_email', 'user_phone', 'user_role'] as $key) {
                        unset($data[$key]);
                    }
                }

                return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
            }
            $rows = collect($api->customers())->map(fn ($c) => \Illuminate\Support\Arr::only($c, ['id', 'customerNumber', 'name', 'organizationNumber']));

            return response()->json(DomainApiRead::paginateCollection($rows, $request))->header('Cache-Control', 'private, no-store');
        } catch (\App\Modules\Integration\Exceptions\TripletexException $error) {
            return response()->json(['message' => 'Tripletex customer lookup could not be verified.'], 503);
        }
    }

    public function number(Request $request)
    {
        abort_unless(app(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class)->connection(), 409, 'Tripletex customer synchronization is paused.');
        try {
            return response()->json(['data' => ['authority' => 'tripletex', 'suggestion' => app(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class)->suggestion(), 'reserved' => false]])->header('Cache-Control', 'private, no-store');
        } catch (\App\Modules\Integration\Exceptions\TripletexException $error) {
            return response()->json(['message' => 'Tripletex number lookup could not be verified.'], 503);
        }
    }

    public function rmm(Request $request)
    {
        $reconcile = (bool) ($request->route()->defaults['rmm_reconcile'] ?? false);
        DomainApiInput::validate($request, $reconcile ? ['external_id' => 'nullable|string|max:255'] : []);
        $target = $request->route('site') ? ClientSite::query()->findOrFail($request->route('site')) : Client::query()->findOrFail($request->route('client'));
        if (! $reconcile) {
            abort_unless(config('clients-api.provider_writes_enabled'), 409, 'Client API provider writes are disabled.');
        }
        $result = app(ExecuteClientCommand::class)->handle($request, fn () => null, function () use ($request, $target, $reconcile) {
            ClientRevision::check($request, $target);

            return ['data' => app(ProvisionClientRmm::class)->handle($target, $request->user()->id, $reconcile, $request->input('external_id'))];
        }, true, true);

        return response()->json($result, 202);
    }
}
