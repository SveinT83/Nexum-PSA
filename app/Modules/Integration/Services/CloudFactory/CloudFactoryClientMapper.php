<?php

namespace App\Modules\Integration\Services\CloudFactory;

use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use App\Modules\Clients\Actions\CreateClientWithDefaults;
use App\Modules\Integration\Models\CloudFactory\ClientLink;
use App\Modules\Integration\Models\CloudFactory\Conflict;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CloudFactoryClientMapper
{
    public function __construct(
        private readonly CreateClientWithDefaults $createClient,
        private readonly CloudFactoryApiFactory $apiFactory,
        private readonly CloudFactoryAudit $audit,
    ) {}

    public function import(Integration $integration, array $customer): array
    {
        $externalId = (string) ($customer['id'] ?? '');

        if ($externalId === '') {
            return ['status' => 'skipped'];
        }

        $link = ClientLink::query()
            ->where('integration_id', $integration->id)
            ->where('external_customer_id', $externalId)
            ->first();

        if ($link) {
            return $this->reconcile($link, $customer);
        }

        [$client, $method, $ambiguous] = $this->match($customer);

        if ($ambiguous) {
            Conflict::query()->firstOrCreate(
                [
                    'integration_id' => $integration->id,
                    'conflict_type' => 'client_match',
                    'external_id' => $externalId,
                    'status' => 'open',
                ],
                [
                    'candidate_ids' => $ambiguous,
                    'provider_payload' => $customer,
                ]
            );

            return ['status' => 'conflict'];
        }

        if (! $client && data_get($integration->config, 'create_missing_clients', true)) {
            $client = $this->createFromCustomer($customer);
            $method = 'provider_created';
        }

        if (! $client) {
            return ['status' => 'skipped'];
        }

        $link = ClientLink::query()->create([
            'integration_id' => $integration->id,
            'client_id' => $client->id,
            'external_customer_id' => $externalId,
            'match_method' => $method,
            'last_synced_snapshot' => $this->snapshot($client, $customer),
            'provider_payload' => $customer,
            'last_synced_at' => now(),
        ]);

        $this->audit->record('client.linked', $integration, $client->id, $link, [
            'match_method' => $method,
            'external_customer_id' => $externalId,
        ]);

        return ['status' => $method === 'provider_created' ? 'created' : 'linked', 'link' => $link];
    }

    public function linkManually(Integration $integration, Client $client, array $customer): ClientLink
    {
        $externalId = (string) ($customer['id'] ?? '');

        if ($externalId === '') {
            throw ValidationException::withMessages([
                'client_id' => 'The Cloud Factory customer is missing its external identifier.',
            ]);
        }

        $externalLink = ClientLink::query()
            ->where('integration_id', $integration->id)
            ->where('external_customer_id', $externalId)
            ->first();
        if ($externalLink && (int) $externalLink->client_id !== (int) $client->id) {
            throw ValidationException::withMessages([
                'client_id' => 'This Cloud Factory customer is already linked to another Nexum Client.',
            ]);
        }

        $clientLink = ClientLink::query()
            ->where('integration_id', $integration->id)
            ->where('client_id', $client->id)
            ->first();
        if ($clientLink && $clientLink->external_customer_id !== $externalId) {
            throw ValidationException::withMessages([
                'client_id' => 'This Nexum Client is already linked to another Cloud Factory customer.',
            ]);
        }

        $link = ClientLink::query()->updateOrCreate(
            ['integration_id' => $integration->id, 'external_customer_id' => $externalId],
            [
                'client_id' => $client->id,
                'match_method' => 'manual',
                'last_synced_snapshot' => $this->snapshot($client, $customer),
                'provider_payload' => $customer,
                'last_synced_at' => now(),
            ]
        );

        Conflict::query()
            ->where('integration_id', $integration->id)
            ->where('external_id', $externalId)
            ->where('status', 'open')
            ->update(['status' => 'resolved', 'resolved_by' => auth()->id(), 'resolved_at' => now()]);

        $this->audit->record('client.manual_linked', $integration, $client->id, $link, [
            'external_customer_id' => $externalId,
        ]);

        return $link;
    }

    public function createRemote(Integration $integration, Client $client): ClientLink
    {
        if ($existing = ClientLink::query()
            ->where('integration_id', $integration->id)
            ->where('client_id', $client->id)
            ->first()) {
            return $existing;
        }

        $payload = $this->remotePayload($integration, $client);
        $customer = $this->apiFactory->make($integration)->post('/v2/customers/customers', $payload);

        return $this->linkManually($integration, $client, $customer);
    }

    private function reconcile(ClientLink $link, array $customer): array
    {
        $client = $link->client;
        $before = $link->last_synced_snapshot ?? [];
        $remote = $this->remoteFields($customer);
        $local = $this->localFields($client);
        $conflicts = [];
        $localUpdate = [];
        $pushRemote = false;

        foreach (['name', 'org_no', 'billing_email'] as $field) {
            $previous = $before[$field] ?? null;
            $localChanged = $this->different($local[$field] ?? null, $previous);
            $remoteChanged = $this->different($remote[$field] ?? null, $previous);

            if ($localChanged && $remoteChanged && $this->different($local[$field] ?? null, $remote[$field] ?? null)) {
                $conflicts[$field] = ['local' => $local[$field] ?? null, 'remote' => $remote[$field] ?? null];

                continue;
            }

            if ($remoteChanged && filled($remote[$field] ?? null)) {
                $localUpdate[$field] = $remote[$field];
            } elseif ($localChanged) {
                $pushRemote = true;
            }
        }

        if ($conflicts) {
            Conflict::query()->firstOrCreate(
                [
                    'integration_id' => $link->integration_id,
                    'conflict_type' => 'client_fields',
                    'external_id' => $link->external_customer_id,
                    'status' => 'open',
                ],
                [
                    'client_id' => $client->id,
                    'fields' => $conflicts,
                    'provider_payload' => $customer,
                ]
            );

            return ['status' => 'conflict', 'link' => $link];
        }

        if ($localUpdate) {
            $client->forceFill($localUpdate)->save();
        }

        if ($pushRemote && data_get($link->integration->config, 'push_client_updates', true)) {
            $customer = $this->apiFactory->make($link->integration)->put(
                '/v2/customers/customers/'.$link->external_customer_id,
                $this->remotePayload($link->integration, $client, $customer)
            );
        }

        $link->forceFill([
            'last_synced_snapshot' => $this->snapshot($client->refresh(), $customer),
            'provider_payload' => $customer,
            'last_synced_at' => now(),
        ])->save();

        return ['status' => ($localUpdate || $pushRemote) ? 'updated' : 'unchanged', 'link' => $link];
    }

    private function match(array $customer): array
    {
        $vatId = $this->normal($customer['vatId'] ?? null);

        if ($vatId) {
            $matches = Client::query()->whereRaw(
                "REPLACE(REPLACE(REPLACE(LOWER(org_no), ' ', ''), '-', ''), '.', '') = ?",
                [$vatId]
            )->get();

            if ($matches->count() === 1) {
                return [$matches->first(), 'org_no', []];
            }

            if ($matches->count() > 1) {
                return [null, null, $matches->pluck('id')->all()];
            }
        }

        $email = Str::lower(trim((string) ($customer['invoiceEmail'] ?? $customer['email'] ?? '')));
        $name = Str::lower(trim((string) ($customer['name'] ?? '')));

        if ($email && $name) {
            $matches = Client::query()
                ->whereRaw('LOWER(name) = ?', [$name])
                ->whereRaw('LOWER(billing_email) = ?', [$email])
                ->get();

            if ($matches->count() === 1) {
                return [$matches->first(), 'name_email', []];
            }

            if ($matches->count() > 1) {
                return [null, null, $matches->pluck('id')->all()];
            }
        }

        return [null, null, []];
    }

    private function createFromCustomer(array $customer): Client
    {
        $address = $customer['address'] ?? [];
        $email = $customer['invoiceEmail'] ?? $customer['email'] ?? null;

        return $this->createClient->handle([
            'name' => $customer['name'] ?: 'Cloud Factory customer',
            'org_no' => $customer['vatId'] ?? null,
            'billing_email' => $email,
            'active' => true,
            'site_name' => $customer['name'] ?: 'Main site',
            'site_address' => $address['streetName'] ?? null,
            'site_co_address' => $address['streetName2'] ?? null,
            'site_zip' => $address['postalCode'] ?? null,
            'site_city' => $address['city'] ?? null,
            'site_county' => $address['region'] ?? null,
            'site_country' => $address['countryCode'] ?? $customer['countryCode'] ?? null,
            'user_name' => $customer['invoiceContactName'] ?? $customer['name'] ?? 'Cloud Factory contact',
            'user_email' => $email,
            'user_phone' => $customer['phone'] ?? null,
        ])['client'];
    }

    private function remotePayload(
        Integration $integration,
        Client $client,
        array $provider = [],
    ): array {
        $site = $client->sites()->where('is_default', true)->first() ?? $client->sites()->first();
        $email = $client->billing_email ?: ($provider['invoiceEmail'] ?? $provider['email'] ?? null);

        if (! $email) {
            throw new \InvalidArgumentException('The Client needs a billing email before Cloud Factory customer creation.');
        }

        return [
            'name' => $client->name,
            'email' => $provider['email'] ?? $email,
            'vatId' => $client->org_no ?: ($provider['vatId'] ?? ''),
            'phone' => $provider['phone'] ?? '',
            'countryCode' => $provider['countryCode'] ?? data_get($integration->config, 'default_country_code', 'NO'),
            'address' => [
                'streetName' => $site?->address ?? data_get($provider, 'address.streetName', ''),
                'streetName2' => $site?->co_address ?? data_get($provider, 'address.streetName2'),
                'city' => $site?->city ?? data_get($provider, 'address.city', ''),
                'region' => $site?->county ?? data_get($provider, 'address.region', ''),
                'postalCode' => $site?->zip ?? data_get($provider, 'address.postalCode', ''),
                'countryCode' => $site?->country ?? data_get($provider, 'address.countryCode', data_get($integration->config, 'default_country_code', 'NO')),
            ],
            'partnerId' => data_get($integration->config, 'partner.id'),
            'externalServices' => $provider['externalServices'] ?? (object) [],
            'tags' => $provider['tags'] ?? [],
            'customerReference' => $client->client_number,
            'displayCurrency' => data_get($integration->config, 'default_currency', 'NOK'),
            'invoiceCurrency' => data_get($integration->config, 'default_currency', 'NOK'),
            'invoiceContactName' => $provider['invoiceContactName'] ?? $client->name,
            'invoiceEmail' => $email,
        ];
    }

    private function snapshot(Client $client, array $customer): array
    {
        return array_replace($this->remoteFields($customer), $this->localFields($client));
    }

    private function localFields(Client $client): array
    {
        return [
            'name' => $client->name,
            'org_no' => $client->org_no,
            'billing_email' => $client->billing_email,
        ];
    }

    private function remoteFields(array $customer): array
    {
        return [
            'name' => $customer['name'] ?? null,
            'org_no' => $customer['vatId'] ?? null,
            'billing_email' => $customer['invoiceEmail'] ?? $customer['email'] ?? null,
        ];
    }

    private function different(mixed $left, mixed $right): bool
    {
        return $this->normal($left) !== $this->normal($right);
    }

    private function normal(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return preg_replace('/[^a-z0-9@]+/i', '', Str::lower(trim((string) $value))) ?: null;
    }
}
