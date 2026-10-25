<?php

namespace App\Modules\Clients\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use App\Modules\Clients\Actions\ExecuteClientCommand;
use App\Modules\Clients\Actions\PortalClientLicenceWorkflow;
use App\Modules\Clients\Actions\StaffClientLicenceWorkflow;
use App\Modules\Clients\Support\ClientLicenceRevision;
use App\Modules\Commercial\Actions\RecordLegalAcceptance;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\CustomerPortal\Support\CustomerPortalContextResolver;
use App\Modules\Integration\Jobs\CloudFactorySyncJob;
use App\Modules\Integration\Models\CloudFactory\Offer;
use App\Modules\Integration\Models\CloudFactory\Operation;
use App\Modules\Integration\Models\CloudFactory\Subscription;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryIntegration;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryLicenceService;
use App\Modules\Integration\Support\DomainApiInput;
use App\Modules\Integration\Support\DomainApiRead;
use Illuminate\Http\Request;

/** Provider outcomes and local/legal evidence remain separate from confirmed external state. */
class ClientLicenceController extends Controller
{
    private function client(Request $request): Client
    {
        if ($request->route()->defaults['clients_portal'] ?? false) {
            $old = $request->attributes->get('customerPortalContext');
            $context = app(CustomerPortalContextResolver::class)->resolveForUser($request->user()->fresh(), $old?->membership->id);
            abort_unless($context && $context->membership->id === $old?->membership->id && $context->site === null
                && $context->membership->role === CustomerPortalMembership::ROLE_CUSTOMER_ADMIN, 403, 'Current client-wide Customer admin access is required.');
            $request->attributes->set('customerPortalContext', $context);

            return $context->client;
        }

        return Client::query()->findOrFail($request->route('client'));
    }

    private function operationData(Operation $op, bool $portal): array
    {
        return $op->only(['id', 'subscription_id', 'action', 'status', 'submitted_at', 'confirmed_at', 'failed_at'])
            + ['confirmed' => $op->status === 'confirmed', 'reconciliation_required' => in_array($op->status, ['failed', 'unknown', 'pending'], true)];
    }

    public function index(Request $request)
    {
        $client = $this->client($request);
        $portal = (bool) ($request->route()->defaults['clients_portal'] ?? false);
        $integration = Integration::query()->where('type', 'cloudfactory')->first();
        $rows = $integration ? Subscription::query()->where('integration_id', $integration->id)->where('client_id', $client->id)
            ->orderBy('id')->get()->map(fn ($s) => $s->only(['id', 'name', 'provider_family', 'quantity', 'status', 'auto_renew', 'renewal_quantity', 'commitment_start_date', 'commitment_end_date', 'contract_id', 'contract_item_id', 'service_id'])
                + ['revision' => ClientLicenceRevision::of($client)]) : collect();

        return response()->json(DomainApiRead::paginateCollection($rows, $request));
    }

    public function context(Request $request)
    {
        $client = $this->client($request);
        $integration = Integration::query()->where('type', 'cloudfactory')->first();
        $portal = (bool) ($request->route()->defaults['clients_portal'] ?? false);
        $offers = $integration ? Offer::query()->where('integration_id', $integration->id)->where('sell_enabled', true)->where('excluded', false)->whereNotNull('service_id')
            ->whereIn('provider_family', ['microsoft', 'adobe'])->orderBy('id')->get() : collect();
        $items = ContractItem::query()->whereHas('contract', fn ($q) => $q->where('client_id', $client->id)->where('approval_status', 'won')
            ->whereDate('start_date', '<=', today())->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->when($portal, fn ($q) => $q->where('allow_license_additions', true)))
            ->whereNotNull('cloudfactory_offer_id')->whereHas('cloudFactoryOffer', fn ($q) => $q->where('sell_enabled', true)->where('excluded', false)->where('purchasable', true))
            ->with(['service.serviceTerms.currentVersion', 'contract.termSnapshots'])->orderBy('id')->get();
        if ($portal) {
            $offers = $offers->whereIn('id', $items->pluck('cloudfactory_offer_id')->all())->values();
        }

        return response()->json(['data' => ['client_id' => $client->id, 'revision' => ClientLicenceRevision::of($client),
            'provider_configured' => $integration?->status === 'active',
            'api_provider_writes_enabled' => (bool) config('clients-api.provider_writes_enabled'),
            'provider_writes_enabled' => (bool) data_get($integration?->config, 'writes_enabled', false),
            'actions' => $portal ? ['issue', 'quantity', 'renewal'] : ['issue', 'quantity', 'renewal', 'status'],
            'offers' => $offers->map(fn ($o) => $o->only(['id', 'name', 'provider_family', 'service_id', 'vendor_id', 'currency', 'recurrence_term', 'billing_term'])),
            'contract_items' => $items->map(fn ($i) => $i->only(['id', 'contract_id', 'service_id', 'cloudfactory_offer_id', 'name', 'unit_price', 'quantity'])
                + ['terms' => $i->service?->serviceTerms->map(fn ($t) => ['id' => $t->id, 'version_id' => $t->currentVersion?->id,
                    'name' => $t->currentVersion?->name ?? $t->name, 'checksum' => $t->currentVersion?->checksum,
                    'content' => $t->currentVersion?->content, 'source_url' => $t->currentVersion?->source_url ?? $t->source_url])->values()->all() ?? []]),
        ]]);
    }

    public function operations(Request $request)
    {
        $client = $this->client($request);

        return response()->json(DomainApiRead::paginateCollection(Operation::query()->where('client_id', $client->id)->orderByDesc('id')->get()
            ->map(fn ($o) => $this->operationData($o, (bool) ($request->route()->defaults['clients_portal'] ?? false))), $request));
    }

    public function operation(Request $request)
    {
        $client = $this->client($request);
        $op = Operation::query()->where('client_id', $client->id)->findOrFail($request->route('operation'));

        return response()->json(['data' => $this->operationData($op, (bool) ($request->route()->defaults['clients_portal'] ?? false))]);
    }

    public function write(Request $request)
    {
        $client = $this->client($request);
        $portal = (bool) ($request->route()->defaults['clients_portal'] ?? false);
        $kind = $request->route()->defaults['licence_action'];
        $rules = match ($kind) {
            'issue' => $portal ? ['contract_item_id' => 'required|integer|min:1', 'quantity' => 'required|integer|min:1|max:100000']
                : ['offer_id' => 'required|uuid', 'quantity' => 'required|integer|min:1|max:100000'],
            'quantity' => ['quantity' => 'required|integer|min:0|max:100000'],
            'renewal' => ['enabled' => 'required|boolean'],
            'status' => ['status' => 'required|in:activate,suspend'],
        };
        if ($portal) {
            $rules += ['name' => 'required|string|max:255', 'confirm' => 'required|accepted'];
        }
        DomainApiInput::validate($request, $rules);
        abort_unless(config('clients-api.provider_writes_enabled'), 409, 'Client API provider writes are disabled.');
        $request->attributes->set('client_external_resource', 'cloudfactory:'.$client->id);
        $authorize = function () use ($request, $client) {
            abort_unless((int) $this->client($request)->id === (int) $client->id, 403);
            abort_unless(config('clients-api.provider_writes_enabled'), 409);
            if ($request->route('subscription')) {
                Subscription::query()->where('client_id', $client->id)->findOrFail($request->route('subscription'));
            }
        };
        $result = app(ExecuteClientCommand::class)->handle($request, $authorize, function () use ($request, $client, $portal, $kind) {
            $given = $request->header('If-Match');
            abort_unless($given, 428, 'Read the licence context revision and supply If-Match.');
            abort_unless(hash_equals(ClientLicenceRevision::of($client), trim($given, '"')), 409, 'Licence context changed; review it again.');
            $integration = Integration::query()->where('type', 'cloudfactory')->where('status', 'active')->firstOrFail();
            $subscription = $request->route('subscription') ? Subscription::query()->where('client_id', $client->id)->findOrFail($request->route('subscription')) : null;
            abort_if($subscription && (string) $subscription->integration_id !== (string) $integration->id, 404);
            $workflow = app($portal ? PortalClientLicenceWorkflow::class : StaffClientLicenceWorkflow::class);
            $args = [$request];
            if (! $portal) {
                $args[] = $client;
            }
            if ($subscription) {
                $args[] = $subscription;
            }
            array_push($args, app(CloudFactoryIntegration::class), app(CloudFactoryLicenceService::class));
            if ($portal) {
                $args[] = app(RecordLegalAcceptance::class);
            }
            $method = $kind === 'issue' ? 'issue' : $kind;
            $operation = $workflow->$method(...$args);
            CloudFactorySyncJob::dispatch('subscriptions')->delay(now()->addSeconds(30));

            return ['provider_operation_id' => $operation->id, 'client_id' => $client->id];
        }, true, true);
        if (isset($result['provider_operation_id'])) {
            $op = Operation::query()->where('client_id', $client->id)->findOrFail($result['provider_operation_id']);
            $result['data'] = $this->operationData($op, $portal);
        }

        return response()->json($result, 202);
    }
}
