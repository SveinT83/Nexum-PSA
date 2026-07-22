<?php

namespace App\Modules\Integration\Services\CloudFactory;

use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Integration\Models\CloudFactory\ClientLink;
use App\Modules\Integration\Models\CloudFactory\Offer;
use App\Modules\Integration\Models\CloudFactory\Operation;
use App\Modules\Integration\Models\CloudFactory\Subscription;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CloudFactoryLicenceService
{
    public function __construct(
        private readonly CloudFactoryApiFactory $apiFactory,
        private readonly CloudFactoryClientMapper $clients,
        private readonly CloudFactoryAudit $audit,
    ) {}

    public function issue(
        Integration $integration,
        Client $client,
        Offer $offer,
        int $quantity,
        ?int $actorId = null,
        ?ContractItem $contractItem = null,
    ): Operation {
        $this->guardWrite($integration, $client, $offer->provider_family);
        $item = $contractItem
            ? $this->validateContractItem($client, $offer, $contractItem)
            : $this->contractItem($client, $offer, 'issue');
        $link = ClientLink::query()
            ->where('integration_id', $integration->id)
            ->where('client_id', $client->id)
            ->first() ?? $this->clients->createRemote($integration, $client);

        $operation = $this->operation($integration, $client, null, 'issue', [
            'external_customer_id' => $link->external_customer_id,
            'external_product_id' => $offer->external_product_id,
            'offer_id' => $offer->id,
            'contract_id' => $item->contract_id,
            'contract_item_id' => $item->id,
            'quantity' => $quantity,
        ], $offer->provider_family, $actorId);

        $api = $this->apiFactory->make($integration);

        return $this->submit($operation, fn (): array => match ($offer->provider_family) {
            'adobe' => $api->post(
                '/v1/adobe/customers/'.$link->external_customer_id.'/subscriptions',
                [
                    'submissionId' => $operation->idempotency_key,
                    'offerId' => $offer->external_product_id,
                    'renewalQuantity' => $quantity,
                ]
            ),
            'microsoft' => $api->post(
                '/Microsoft/Seats/'.$link->external_customer_id,
                [
                    'productId' => $offer->external_product_id,
                    'quantity' => $quantity,
                    'billingCycleType' => (int) data_get(
                        $integration->config,
                        'microsoft_billing_cycle_type',
                        1
                    ),
                ]
            ),
            default => throw ValidationException::withMessages([
                'provider' => 'This Cloud Factory provider does not expose an implemented issue operation.',
            ]),
        });
    }

    public function changeQuantity(
        Integration $integration,
        Subscription $subscription,
        int $quantity,
        ?int $actorId = null,
    ): Operation {
        $subscription->loadMissing(['client', 'clientLink', 'offer', 'contract']);
        $this->guardWrite($integration, $subscription->client, $subscription->provider_family);
        $this->guardQuantity($subscription, $quantity);

        $operation = $this->operation($integration, $subscription->client, $subscription, 'quantity', [
            'external_customer_id' => $subscription->clientLink?->external_customer_id,
            'external_subscription_id' => $subscription->external_subscription_id,
            'external_product_id' => $subscription->offer?->external_product_id,
            'quantity' => $quantity,
            'previous_quantity' => $subscription->quantity,
        ], $subscription->provider_family, $actorId);

        $api = $this->apiFactory->make($integration);

        return $this->submit($operation, function () use ($api, $subscription, $quantity, $operation): array {
            if ($subscription->provider_family === 'microsoft') {
                $payload = array_replace($subscription->provider_payload ?? [], [
                    'id' => $subscription->external_subscription_id,
                    'quantity' => $quantity,
                    'targetQuantity' => $quantity,
                    'productId' => $subscription->offer?->external_product_id,
                    'sku' => $subscription->offer?->sku,
                ]);

                return $api->patch(
                    '/Microsoft/Seat/'.$subscription->clientLink->external_customer_id.'/Quantity?acceptedTOS=true',
                    $payload
                );
            }

            if ($subscription->provider_family === 'adobe' && $quantity > $subscription->quantity) {
                return $api->post(
                    '/v1/adobe/customers/'.$subscription->clientLink->external_customer_id.'/orders',
                    [
                        'submissionId' => $operation->idempotency_key,
                        'externalReferenceId' => $operation->idempotency_key,
                        'type' => 'new',
                        'lineItems' => [[
                            'offerId' => $subscription->offer?->external_product_id,
                            'subscriptionId' => $subscription->external_subscription_id,
                            'quantity' => $quantity - $subscription->quantity,
                        ]],
                    ]
                );
            }

            throw ValidationException::withMessages([
                'quantity' => 'Cloud Factory does not publish a supported immediate Adobe decrease operation for this subscription.',
            ]);
        });
    }

    public function setAutoRenew(
        Integration $integration,
        Subscription $subscription,
        bool $enabled,
        ?int $actorId = null,
    ): Operation {
        $subscription->loadMissing(['client', 'clientLink']);
        $this->guardWrite($integration, $subscription->client, $subscription->provider_family);

        $operation = $this->operation($integration, $subscription->client, $subscription, 'renewal', [
            'external_customer_id' => $subscription->clientLink?->external_customer_id,
            'external_subscription_id' => $subscription->external_subscription_id,
            'enabled' => $enabled,
            'quantity' => $subscription->quantity,
        ], $subscription->provider_family, $actorId);

        $api = $this->apiFactory->make($integration);

        return $this->submit($operation, fn (): array => match ($subscription->provider_family) {
            'microsoft' => $api->patch(
                '/MicrosoftSubscriptionsManagement/'.$subscription->clientLink->external_customer_id.
                '/Subscription/'.$subscription->external_subscription_id.'/ToogleAutoRenew',
                ['autoRenew' => $enabled]
            ),
            'adobe' => $api->patch(
                '/v1/adobe/customers/'.$subscription->clientLink->external_customer_id.
                '/subscriptions/'.$subscription->external_subscription_id,
                [
                    'submissionId' => $operation->idempotency_key,
                    'enabled' => $enabled,
                    'renewalQuantity' => $subscription->quantity,
                ]
            ),
            default => throw ValidationException::withMessages([
                'provider' => 'This provider does not expose an implemented renewal operation.',
            ]),
        });
    }

    public function setMicrosoftStatus(
        Integration $integration,
        Subscription $subscription,
        string $status,
        ?int $actorId = null,
    ): Operation {
        $subscription->loadMissing(['client', 'clientLink']);
        $this->guardWrite($integration, $subscription->client, 'microsoft');

        if ($subscription->provider_family !== 'microsoft' || ! in_array($status, ['activate', 'suspend'], true)) {
            throw ValidationException::withMessages(['status' => 'Unsupported subscription status action.']);
        }

        $operation = $this->operation($integration, $subscription->client, $subscription, $status, [
            'external_customer_id' => $subscription->clientLink?->external_customer_id,
            'external_subscription_id' => $subscription->external_subscription_id,
            'quantity' => $subscription->quantity,
        ], 'microsoft', $actorId);

        $api = $this->apiFactory->make($integration);

        return $this->submit($operation, fn (): array => $api->patch(
            '/Microsoft/Seat/'.$subscription->clientLink->external_customer_id.
            '/Status/'.ucfirst($status).'?acceptedTOS=true',
            array_replace($subscription->provider_payload ?? [], [
                'id' => $subscription->external_subscription_id,
            ])
        ));
    }

    private function guardWrite(Integration $integration, Client $client, ?string $provider): void
    {
        if ($integration->status !== 'active' || ! data_get($integration->config, 'writes_enabled', false)) {
            throw ValidationException::withMessages([
                'integration' => 'Cloud Factory writes are disabled in Integration settings.',
            ]);
        }

        if (data_get($integration->config, 'write_scope', 'test_client') === 'test_client'
            && (int) data_get($integration->config, 'test_client_id') !== (int) $client->id) {
            throw ValidationException::withMessages([
                'client' => 'Cloud Factory writes are currently restricted to the allowlisted fictitious Client.',
            ]);
        }

        $role = match ($provider) {
            'microsoft' => 'Microsoft Full Access',
            'adobe' => 'Adobe',
            default => 'Partner',
        };

        $api = $this->apiFactory->make($integration);

        if (! $api->hasRole($role) && ! data_get($integration->config, 'capabilities.'.$provider, false)) {
            throw ValidationException::withMessages([
                'provider' => 'The dedicated Cloud Factory account is missing the required '.$role.' role.',
            ]);
        }
    }

    private function validateContractItem(Client $client, Offer $offer, ContractItem $item): ContractItem
    {
        $item->loadMissing('contract');
        $today = now()->toDateString();
        $eligible = (int) $item->contract?->client_id === (int) $client->id
            && $item->cloudfactory_offer_id === $offer->id
            && $item->contract?->approval_status === 'won'
            && $item->contract?->allow_license_additions
            && $item->contract?->start_date?->toDateString() <= $today
            && (! $item->contract?->end_date || $item->contract->end_date->toDateString() >= $today);

        if (! $eligible) {
            throw ValidationException::withMessages([
                'contract' => 'The selected contract line is not eligible for this Cloud Factory licence order.',
            ]);
        }

        return $item;
    }

    private function contractItem(Client $client, Offer $offer, string $action): ContractItem
    {
        $today = now()->toDateString();
        $contract = Contracts::query()
            ->where('client_id', $client->id)
            ->where('approval_status', 'won')
            ->whereDate('start_date', '<=', $today)
            ->where(function ($query) use ($today): void {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })
            ->where($action === 'issue' ? 'allow_license_additions' : 'allow_license_increases', true)
            ->latest('accepted_at')
            ->latest('id')
            ->first();

        $item = $contract?->items()
            ->where('service_id', $offer->service_id)
            ->where('cloudfactory_offer_id', $offer->id)
            ->first();

        if (! $contract || ! $item) {
            throw ValidationException::withMessages([
                'contract' => 'A won, active contract with this exact Cloud Factory Service variant is required before issuing the licence.',
            ]);
        }

        return $item;
    }

    private function guardQuantity(Subscription $subscription, int $quantity): void
    {
        if ($quantity < 0 || ! $subscription->contract) {
            throw ValidationException::withMessages(['contract' => 'The subscription is not linked to an eligible contract.']);
        }

        if ($quantity > $subscription->quantity && ! $subscription->contract->allow_license_increases) {
            throw ValidationException::withMessages(['quantity' => 'The contract does not allow licence increases.']);
        }

        if ($quantity < $subscription->quantity
            && ! ($subscription->contract->allow_license_decreases || $subscription->contract->allow_decrease_during_binding)) {
            throw ValidationException::withMessages([
                'quantity' => 'The contract does not allow licence decreases.',
            ]);
        }
    }

    private function operation(
        Integration $integration,
        Client $client,
        ?Subscription $subscription,
        string $action,
        array $payload,
        ?string $provider,
        ?int $actorId,
    ): Operation {
        $fingerprint = hash('sha256', json_encode([
            $integration->id,
            $client->id,
            $subscription?->id,
            $action,
            $payload,
        ], JSON_THROW_ON_ERROR));

        return Operation::query()->firstOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'integration_id' => $integration->id,
                'client_id' => $client->id,
                'subscription_id' => $subscription?->id,
                'idempotency_key' => (string) Str::uuid(),
                'provider_family' => $provider,
                'action' => $action,
                'status' => 'pending',
                'request_payload' => $payload,
                'created_by' => $actorId ?? auth()->id(),
            ]
        );
    }

    private function submit(Operation $operation, callable $request): Operation
    {
        if (in_array($operation->status, ['submitted', 'confirmed'], true)) {
            return $operation;
        }

        if ($operation->status === 'failed') {
            $operation->forceFill([
                'status' => 'pending',
                'failed_at' => null,
            ])->save();
        }

        try {
            return $this->submitted($operation, $request());
        } catch (Throwable $exception) {
            $operation->forceFill([
                'status' => 'failed',
                'attempts' => $operation->attempts + 1,
                'last_error' => Str::limit($exception->getMessage(), 500),
                'failed_at' => now(),
            ])->save();

            $this->audit->record(
                'licence.'.$operation->action.'_failed',
                $operation->integration,
                $operation->client_id,
                $operation,
                [
                    'provider_family' => $operation->provider_family,
                    'operation_id' => $operation->id,
                    'exception' => $exception::class,
                ],
                $operation->created_by
            );

            throw $exception;
        }
    }

    private function submitted(Operation $operation, array $response): Operation
    {
        $operation->forceFill([
            'status' => 'submitted',
            'response_payload' => $response,
            'external_operation_id' => $response['submissionId']
                ?? $response['operationId']
                ?? $response['id']
                ?? null,
            'attempts' => $operation->attempts + 1,
            'submitted_at' => now(),
            'last_error' => null,
        ])->save();

        $this->audit->record(
            'licence.'.$operation->action.'_submitted',
            $operation->integration,
            $operation->client_id,
            $operation,
            [
                'provider_family' => $operation->provider_family,
                'operation_id' => $operation->id,
            ],
            $operation->created_by
        );

        return $operation->refresh();
    }
}
