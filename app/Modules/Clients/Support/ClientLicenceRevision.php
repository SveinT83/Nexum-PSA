<?php

namespace App\Modules\Clients\Support;

use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Support\CommercialRevision;
use App\Modules\Integration\Models\CloudFactory\Offer;
use App\Modules\Integration\Models\CloudFactory\Operation;
use App\Modules\Integration\Models\CloudFactory\Subscription;

/** Approvals bind Client, provider settings, all relevant offers/contracts/terms and operation state. */
class ClientLicenceRevision
{
    public static function of(Client $client): string
    {
        $integration = Integration::query()->where('type', 'cloudfactory')->first();

        return hash('sha256', json_encode(CommercialRevision::stable([
            $client->fresh()?->getRawOriginal(), $integration?->getRawOriginal(),
            $integration ? Offer::query()->where('integration_id', $integration->id)->orderBy('id')->get()->map->getRawOriginal()->all() : [],
            ContractItem::query()->whereHas('contract', fn ($q) => $q->where('client_id', $client->id))
                ->with(['contract.termSnapshots', 'service.serviceTerms.currentVersion'])->orderBy('id')->get()->toArray(),
            Subscription::query()->where('client_id', $client->id)->orderBy('id')->get()->map->getRawOriginal()->all(),
            Operation::query()->where('client_id', $client->id)->orderBy('id')->get()->map->getRawOriginal()->all(),
            (bool) config('clients-api.provider_writes_enabled'),
        ]), JSON_THROW_ON_ERROR));
    }
}
