<?php

namespace App\Modules\Clients\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use Illuminate\Support\Facades\DB;

/** Shared local Site persistence and same-parent default transition under a parent lock. */
class SaveClientSite
{
    public function handle(Client $client, array $data, ?ClientSite $site = null): ClientSite
    {
        return DB::transaction(function () use ($client, $data, $site) {
            Client::query()->lockForUpdate()->findOrFail($client->id);
            $attributes = array_intersect_key($data, array_flip(['name', 'address', 'co_address', 'zip', 'city', 'county', 'country', 'is_default']));
            if ($site) {
                $site = ClientSite::query()->where('client_id', $client->id)->lockForUpdate()->findOrFail($site->id);
                $site->fill($attributes)->save();
            } else {
                $site = $client->sites()->create($attributes);
            }
            if ($site->is_default) {
                $client->sites()->where('id', '!=', $site->id)->update(['is_default' => false]);
            }

            return $site;
        });
    }
}
