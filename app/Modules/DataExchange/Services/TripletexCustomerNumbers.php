<?php

namespace App\Modules\DataExchange\Services;

use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use App\Modules\DataExchange\Models\TripletexCustomerLink;
use App\Modules\Integration\Exceptions\TripletexException;
use App\Modules\Integration\Services\Tripletex\TripletexClient;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Number authority and recoverable customer creation. Profile synchronization is deliberately separate. */
class TripletexCustomerNumbers
{
    public function connection(): ?Integration
    {
        if (! config('tripletex.enabled')) {
            return null;
        }

        return Integration::where('type', 'tripletex')->where('status', 'active')->get()
            ->first(fn ($row) => ($row->config['customer_sync_enabled'] ?? false) === true);
    }

    public static function lockKey(string $id): string
    {
        // Setup and both synchronization workflows must agree on the same account lock.
        return SyncTripletexWorkdays::lockKey($id);
    }

    public function suggestion(): ?string
    {
        $connection = $this->connection();
        if (! $connection) {
            return null;
        }
        $this->ready($connection);

        return $this->next(new TripletexClient($connection));
    }

    /** A complete provider snapshot, never an unlabelled cached or partial number. */
    private function next(TripletexClient $api, ?array $customers = null): string
    {
        $customers ??= $api->customers();
        $used = [];
        $highest = 0;
        foreach ($customers as $row) {
            $number = $row['customerNumber'] ?? null;
            if (! is_int($number) || $number < 0) {
                throw new TripletexException('invalid_customer_number');
            }
            $highest = max($highest, $number);
            $used[$number] = true;
        }
        foreach ($api->supplierNumbers() as $row) {
            $number = $row['supplierNumber'] ?? null;
            if (! is_int($number) || $number < 0) {
                throw new TripletexException('invalid_supplier_number');
            }
            $used[$number] = true;
        }
        foreach (Client::pluck('client_number') as $number) {
            if (ctype_digit((string) $number)) {
                $used[(int) $number] = true;
            }
        }
        for ($number = max(1, $highest + 1); $number <= 2147483647; $number++) {
            if (! isset($used[$number])) {
                return (string) $number;
            }
        }
        throw new TripletexException('customer_number_range_exhausted');
    }

    /** Must run outside an application transaction so POST intent survives local rollback. */
    public function create(array $attributes, Closure $afterCreate, Integration $connection): Client
    {
        if (DB::transactionLevel() > 0) {
            $this->fail('Create or link this customer through the Client form or API before importing. Customer creation cannot run inside a batch transaction.');
        }
        $requestKey = $attributes['tripletex_request_key'] ?? null;
        if (! is_string($requestKey) || ! \Illuminate\Support\Str::isUuid($requestKey)) {
            $this->fail('A valid tripletex_request_key UUID is required. Reload the form before saving.');
        }
        $selectedId = $attributes['tripletex_customer_id'] ?? null;
        $siteData = $attributes['tripletex_site'] ?? null;
        unset($attributes['tripletex_request_key'], $attributes['tripletex_customer_id'], $attributes['tripletex_number_mode']);
        // A suggestion is not user-controlled authority and is excluded from the retry hash.
        unset($attributes['client_number']);
        ksort($attributes);
        $payloadHash = hash_hmac('sha256', json_encode([$attributes, $selectedId], JSON_THROW_ON_ERROR), config('app.key'));
        unset($attributes['tripletex_site']);
        $key = hash_hmac('sha256', (string) auth()->id().':'.$requestKey, config('app.key'));

        try {
            return Cache::lock(self::lockKey($connection->id), 600)->block(30, function () use ($connection, $key, $payloadHash, $attributes, $selectedId, $siteData, $afterCreate) {
                $current = $this->connection();
                if (! $current || $current->id !== $connection->id) {
                    $this->fail('Customer synchronization changed. Reload before saving.');
                }
                $this->ready($current);
                $api = new TripletexClient($current);
                $state = TripletexCustomerLink::firstOrCreate(
                    ['connection_id' => $current->id, 'request_key' => $key],
                    ['company_id' => $current->config['company_id'], 'environment' => $current->config['environment'],
                        'payload_hash' => $payloadHash]
                );
                if ($state->company_id !== (int) $current->config['company_id'] || $state->environment !== $current->config['environment']) {
                    $this->fail('The saved attempt belongs to another provider company or environment.');
                }
                if ($state->payload_hash !== $payloadHash) {
                    $this->fail('This creation attempt has different data. Restore the original data or start a new form.');
                }
                if ($state->client_id) {
                    $this->fail('This creation attempt already completed. Open the existing Client.');
                }
                if ($state->status === 'sending' && ! $state->customer_id) {
                    $this->fail('The Tripletex creation outcome is unknown. An administrator must reconcile this attempt before another creation.');
                }

                try {
                    if ($state->customer_id) {
                        $remote = $api->customer($state->customer_id);
                        $this->assertSelectedIdentity($remote, $attributes);
                        if ($state->customer_number && (string) $remote['customerNumber'] !== $state->customer_number) {
                            $this->fail('The provider number changed during recovery. Reconcile this attempt before continuing.');
                        }
                    } elseif ($selectedId) {
                        $remote = $api->customer((int) $selectedId);
                        $this->assertSelectedIdentity($remote, $attributes);
                    } else {
                        $customers = $api->customers();
                        foreach ($customers as $candidate) {
                            if ($this->possibleDuplicate($candidate, $attributes)) {
                                $this->fail('A possible customer already exists in Tripletex. Select that customer explicitly before saving.');
                            }
                        }
                        $number = $this->next($api, $customers);
                        $payload = ['name' => $attributes['name'], 'customerNumber' => (int) $number];
                        if (filled($attributes['org_no'] ?? null)) {
                            $payload['organizationNumber'] = $attributes['org_no'];
                        }
                        if (filled($attributes['billing_email'] ?? null)) {
                            $payload['invoiceEmail'] = $attributes['billing_email'];
                        }
                        if ($siteData !== null && array_filter($siteData, fn ($value) => filled($value))) {
                            $profile = app(\App\Modules\Integration\Services\Tripletex\CustomerProfileData::class);
                            $siteValues = $profile->validated(['billing_email' => (string) ($attributes['billing_email'] ?? '')] + $siteData);
                            $payload['physicalAddress'] = $profile->addressPayload($siteValues, $api);
                        }
                        $state->update(['status' => 'sending', 'customer_number' => $number, 'error_code' => null]);
                        // No retry in transport. A lost response leaves this attempt blocked.
                        $created = $api->createCustomer($payload);
                        $state->update(['customer_id' => $created['id'], 'status' => 'verifying']);
                        $remote = $api->customer($created['id']);
                        if ($remote['customerNumber'] !== (int) $number || $remote['name'] !== $attributes['name']) {
                            throw new TripletexException('customer_write_readback_mismatch');
                        }
                    }
                    $this->assertUsable($remote);
                    $this->assertSelectedIdentity($remote, $attributes);
                    if (TripletexCustomerLink::where('connection_id', $current->id)->where('customer_id', $remote['id'])
                        ->whereKeyNot($state->id)->exists()) {
                        $this->fail('This Tripletex customer is already linked or awaiting recovery.');
                    }
                    $state->update(['customer_id' => $remote['id'], 'customer_number' => (string) $remote['customerNumber'],
                        'status' => 'verified', 'error_code' => null, 'checked_at' => now()]);
                    $this->assertNumberFree((string) $remote['customerNumber']);

                    $profileRemote = $siteData !== null ? $api->customerProfile($remote['id']) : null;

                    return DB::transaction(function () use ($state, $remote, $profileRemote, $attributes, $afterCreate) {
                        $client = Client::create($attributes + ['client_number' => (string) $remote['customerNumber']]);
                        $afterCreate($client);
                        $state->update(['client_id' => $client->id, 'status' => 'linked']);
                        if ($profileRemote !== null) {
                            app(SyncTripletexCustomerProfiles::class)->initialize($state, $profileRemote);
                        }
                        activity()->event('tripletex.customer.created')->withProperties([
                            'client_id' => $client->id, 'connection_id' => $state->connection_id,
                            'customer_id' => $state->customer_id,
                        ])->log('Tripletex customer identity verified and linked');

                        return $client;
                    });
                } catch (TripletexException $exception) {
                    // 4xx responses definitively reject a POST; 5xx/transport failures remain ambiguous.
                    if ($state->status === 'sending' && in_array($exception->status, [400, 401, 403, 409, 422, 429], true)) {
                        $state->status = 'rejected';
                    }
                    $state->error_code = $exception->reason;
                    $state->save();
                    $this->fail($exception->getMessage().' Reload or reconcile the saved attempt; no local fallback was used.');
                }
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'clients_client_number_unique') || str_contains($message, 'clients.client_number')) {
                $this->fail('The provider number collided with another local Client. The provider identity is preserved; resolve the conflict and resubmit the original attempt.');
            }
            throw $e;
        } catch (TripletexException $e) {
            $this->fail($e->getMessage().' No local fallback was used.');
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            $this->fail('Tripletex customer synchronization is busy. Please try again.');
        }
    }

    public function assertNumberFree(string $number, ?int $except = null): void
    {
        $conflict = Client::when($except, fn ($q) => $q->whereKeyNot($except))->pluck('client_number')
            ->contains(fn ($used) => (string) $used === $number || (ctype_digit((string) $used) && (int) $used === (int) $number));
        if ($conflict) {
            $this->fail('The Tripletex customer number is already used by another Nexum Client. Resolve the conflict before linking.');
        }
    }

    public function assertNumberUnchanged(Client $client): void
    {
        if ($client->exists && $client->isDirty('client_number') && \Illuminate\Support\Facades\Schema::hasTable('tripletex_customer_links')
            && TripletexCustomerLink::where('client_id', $client->id)->exists()) {
            $this->fail('Tripletex controls this client number. Use the integration customer-link review to adopt a changed number.');
        }
    }

    public function ready(Integration $connection): void
    {
        if (empty($connection->config['read_verified_at'])
            || ($connection->config['verified_company_id'] ?? null) !== ($connection->config['company_id'] ?? null)) {
            throw new TripletexException('customer_connection_not_verified');
        }
    }

    public function assertUsable(array $remote): void
    {
        if (($remote['isInactive'] ?? true) !== false || ($remote['customerNumber'] ?? 0) < 1) {
            $this->fail('Select an active Tripletex customer with a positive customer number.');
        }
    }

    private function assertSelectedIdentity(array $remote, array $attributes): void
    {
        if (mb_strtolower(trim($remote['name'])) !== mb_strtolower(trim($attributes['name']))
            || (filled($attributes['org_no'] ?? null) && trim($remote['organizationNumber'] ?? '') !== trim($attributes['org_no']))) {
            $this->fail('The selected Tripletex customer does not match the submitted name or organization number. Review the selected customer.');
        }
    }

    private function possibleDuplicate(array $remote, array $attributes): bool
    {
        foreach (['organizationNumber' => 'org_no', 'email' => 'billing_email', 'invoiceEmail' => 'billing_email', 'name' => 'name'] as $external => $local) {
            if (filled($attributes[$local] ?? null) && mb_strtolower(trim((string) ($remote[$external] ?? ''))) === mb_strtolower(trim((string) $attributes[$local]))) {
                return true;
            }
        }

        return false;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['client_number' => $message]);
    }
}
