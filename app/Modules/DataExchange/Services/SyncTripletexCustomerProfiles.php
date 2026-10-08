<?php

namespace App\Modules\DataExchange\Services;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\System\Integrations\Integration;
use App\Modules\DataExchange\Models\TripletexCustomerLink;
use App\Modules\DataExchange\Models\TripletexCustomerProfile;
use App\Modules\Integration\Exceptions\TripletexException;
use App\Modules\Integration\Services\Tripletex\CustomerProfileData;
use App\Modules\Integration\Services\Tripletex\TripletexClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Two-way billing/address reconciliation. Deliberately has no Contact dependency. */
class SyncTripletexCustomerProfiles
{
    public function __construct(private CustomerProfileData $data) {}

    /** Creation stores the provider baseline without changing the user's reviewed local inputs. */
    public function initialize(TripletexCustomerLink $link, array $remote): void
    {
        if (! Schema::hasTable('tripletex_customer_profiles')) {
            return;
        }
        $site = $this->defaultSite($link->client_id);
        if (! $site) {
            return;
        }
        $kind = $this->data->addressKind($remote);
        TripletexCustomerProfile::firstOrCreate(['link_id' => $link->id], [
            'site_id' => $site->id, 'address_kind' => $kind,
            'baseline' => $this->data->remote($remote, $kind), 'status' => 'pending',
        ]);
    }

    public function sync(int $linkId): string
    {
        if (! Schema::hasTable('tripletex_customer_profiles')) {
            return 'unavailable';
        }
        $link = TripletexCustomerLink::find($linkId);
        if (! $link || ! $link->client_id || ! $link->customer_id || $link->status !== 'linked') {
            return 'unavailable';
        }
        try {
            return Cache::lock(TripletexCustomerNumbers::lockKey($link->connection_id), 600)->block(2, function () use ($link) {
                $connection = app(TripletexCustomerNumbers::class)->connection();
                if (! $connection || $connection->id !== $link->connection_id) {
                    return 'paused'; // Pause retains baselines and pending evidence.
                }
                $state = TripletexCustomerProfile::firstOrCreate(['link_id' => $link->id]);
                try {
                    app(TripletexCustomerNumbers::class)->ready($connection);
                    if ($link->company_id !== (int) $connection->config['company_id']
                        || $link->environment !== $connection->config['environment']) {
                        throw new TripletexException('customer_profile_company_changed');
                    }
                    $client = Client::find($link->client_id);
                    if (! $client) {
                        throw new TripletexException('customer_profile_client_missing');
                    }
                    $api = new TripletexClient($connection);
                    $remote = $api->customerProfile($link->customer_id);
                    app(TripletexCustomerNumbers::class)->assertUsable($remote);
                    if ((string) $remote['customerNumber'] !== (string) $client->client_number) {
                        throw new TripletexException('customer_profile_number_changed');
                    }
                    if (! $state->site_id) {
                        $site = $this->defaultSite($client->id);
                        if (! $site) {
                            throw new TripletexException('customer_profile_choose_default_site');
                        }
                        $state->update(['site_id' => $site->id, 'address_kind' => $this->data->addressKind($remote)]);
                    }
                    $site = ClientSite::whereKey($state->site_id)->where('client_id', $client->id)->first();
                    if (! $site) {
                        throw new TripletexException('customer_profile_bound_site_missing');
                    }
                    $provider = $this->data->remote($remote, $state->address_kind);
                    $local = $this->data->local($client, $site, $api);
                    $base = $state->baseline;
                    $conflicts = [];
                    $target = $provider;
                    $acknowledged = [];

                    // Recover a completed/ambiguous PUT using the exact field snapshot and provider version.
                    if ($pending = $state->pending) {
                        foreach ($pending['fields'] as $field) {
                            if ($provider[$field] === $pending['target'][$field]) {
                                $base[$field] = $provider[$field];
                                $acknowledged[] = $field;
                            } elseif ($remote['version'] !== $pending['version']) {
                                // Someone changed the provider after our attempt; provider authority wins.
                                $conflicts[] = $field;
                            }
                        }
                    }
                    if ($base !== null) {
                        foreach (CustomerProfileData::FIELDS as $field) {
                            if (in_array($field, $conflicts, true)) {
                                continue;
                            }
                            if ($local[$field] !== $base[$field] && $provider[$field] === $base[$field]) {
                                $target[$field] = $local[$field];
                            } elseif ($local[$field] !== $base[$field] && $provider[$field] !== $base[$field]
                                && $local[$field] !== $provider[$field]) {
                                $conflicts[] = $field;
                            }
                        }
                    }
                    $exported = array_keys(array_filter($target, fn ($value, $field) => $value !== $provider[$field], ARRAY_FILTER_USE_BOTH));
                    if ($exported !== []) {
                        $patch = [];
                        if (in_array('billing_email', $exported, true)) {
                            $patch['invoiceEmail'] = $target['billing_email'];
                        }
                        if (array_diff($exported, ['billing_email'])) {
                            $patch[$state->address_kind] = $this->data->addressPayload($target, $api, $remote[$state->address_kind] ?? []);
                        }
                        // Persist before HTTP. A process crash must not turn our own PUT into a foreign conflict.
                        $state->update(['pending' => ['local' => $local, 'target' => $target,
                            'fields' => $exported, 'version' => $remote['version']], 'status' => 'pending']);
                        $remote = $api->updateCustomerProfile($link->customer_id, $remote['version'], $patch);
                        if ((string) $remote['customerNumber'] !== (string) $link->customer_number) {
                            throw new TripletexException('customer_profile_number_changed');
                        }
                        $provider = $this->data->remote($remote, $state->address_kind);
                        if ($provider !== $target) {
                            throw new TripletexException('customer_profile_readback_mismatch');
                        }
                        $acknowledged = array_unique(array_merge($acknowledged, $exported));
                    }

                    // HTTP runs outside local row locks. Merge only fields that stayed unchanged locally.
                    return DB::transaction(function () use ($link, $connection, $state, $client, $site, $local, $provider, $base, $api, $conflicts, $acknowledged) {
                        $savedConnection = Integration::whereKey($connection->id)->lockForUpdate()->firstOrFail();
                        if (! config('tripletex.enabled') || $savedConnection->status !== 'active'
                            || ! ($savedConnection->config['customer_sync_enabled'] ?? false)) {
                            return 'paused';
                        }
                        $currentClient = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
                        $currentSite = ClientSite::whereKey($site->id)->where('client_id', $client->id)->lockForUpdate()->first();
                        if (! $currentSite) {
                            throw new TripletexException('customer_profile_bound_site_missing');
                        }
                        if ((string) $currentClient->client_number !== (string) $link->customer_number) {
                            throw new TripletexException('customer_profile_number_changed');
                        }
                        $now = $this->data->local($currentClient, $currentSite, $api);
                        $nextBase = $base ?? $provider;
                        $siteChanges = [];
                        $deferred = false;
                        foreach (CustomerProfileData::FIELDS as $field) {
                            if ($now[$field] === $local[$field]) {
                                if ($field === 'billing_email') {
                                    $currentClient->billing_email = $provider[$field] ?: null;
                                } else {
                                    $siteChanges[$field] = $provider[$field] === '' ? null : $provider[$field];
                                }
                                $nextBase[$field] = $provider[$field];
                            } else {
                                $deferred = true;
                                if (in_array($field, $acknowledged, true)) {
                                    $nextBase[$field] = $provider[$field];
                                }
                            }
                        }
                        if ($currentClient->isDirty()) {
                            $currentClient->save();
                        }
                        $currentSite->fill($siteChanges);
                        if ($currentSite->isDirty()) {
                            $currentSite->save();
                        }
                        $state->update(['baseline' => $nextBase, 'pending' => null,
                            'status' => $deferred ? 'pending' : 'synced', 'checked_at' => now(),
                            'error_code' => null, 'conflict_fields' => array_values(array_unique($conflicts))]);
                        if ($conflicts !== []) {
                            activity()->event('tripletex.customer_profile.conflict')->withProperties([
                                'link_id' => $link->id, 'fields' => array_values(array_unique($conflicts)),
                                'resolution' => 'tripletex',
                            ])->log('Tripletex values retained for conflicting customer profile fields');
                        }

                        return $state->status;
                    });
                } catch (TripletexException $e) {
                    $state->update(['status' => 'attention', 'error_code' => $e->reason, 'checked_at' => now()]);
                } catch (\Throwable) {
                    // Never include customer/address/email values or encrypted snapshots in logs.
                    $state->update(['status' => 'attention', 'error_code' => 'customer_profile_local_failure', 'checked_at' => now()]);
                }

                return 'attention';
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            // Another account operation owns the lease; the next bounded scan retries.
            return 'busy';
        }
    }

    private function defaultSite(int $clientId): ?ClientSite
    {
        $defaults = ClientSite::where('client_id', $clientId)->where('is_default', true)->limit(2)->get();
        if ($defaults->count() === 1) {
            return $defaults->first();
        }
        $sites = ClientSite::where('client_id', $clientId)->limit(2)->get();

        return $sites->count() === 1 ? $sites->first() : null;
    }
}
