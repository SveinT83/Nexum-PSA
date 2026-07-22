<?php

namespace App\Modules\Integration\Services\CloudFactory;

use App\Models\System\Integrations\Integration;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Integration\Exceptions\CloudFactoryApiException;
use App\Modules\Integration\Models\CloudFactory\BillingPeriod;
use App\Modules\Integration\Models\CloudFactory\ClientLink;
use App\Modules\Integration\Models\CloudFactory\Conflict;
use App\Modules\Integration\Models\CloudFactory\LicenceAmendment;
use App\Modules\Integration\Models\CloudFactory\Offer;
use App\Modules\Integration\Models\CloudFactory\Operation;
use App\Modules\Integration\Models\CloudFactory\Subscription;
use App\Modules\Integration\Models\CloudFactory\SyncRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CloudFactorySubscriptionSync
{
    public function __construct(
        private readonly CloudFactoryApiFactory $apiFactory,
        private readonly CloudFactoryServiceManager $services,
        private readonly CloudFactoryAudit $audit,
        private readonly CloudFactorySyncProgress $progress,
    ) {}

    public function run(Integration $integration, SyncRun $run): void
    {
        $api = $this->apiFactory->make($integration);
        $links = ClientLink::query()
            ->where('integration_id', $integration->id)
            ->with('client')
            ->orderBy('id')
            ->get();
        $syncMicrosoft = $api->hasRole('Microsoft Full Access')
            || (bool) data_get($integration->config, 'capabilities.microsoft');
        $syncAdobe = $api->hasRole('Adobe')
            || (bool) data_get($integration->config, 'capabilities.adobe');

        $this->progress->setSourceTotal(
            $run,
            'subscriptions',
            $links->count() * ((int) $syncMicrosoft + (int) $syncAdobe)
        );

        $links->each(function (ClientLink $link) use (
            $integration,
            $run,
            $syncMicrosoft,
            $syncAdobe,
        ): void {
            if ($syncMicrosoft) {
                $this->syncMicrosoft($integration, $link, $run);
            }

            if ($syncAdobe) {
                $this->syncAdobe($integration, $link, $run);
            }
        });

        $this->progress->setMessage($run, 'subscriptions', 'Finalizing Services and contract links.');

        Offer::query()
            ->where('integration_id', $integration->id)
            ->get()
            ->each(function (Offer $offer): void {
                $count = Subscription::query()
                    ->where('offer_id', $offer->id)
                    ->whereIn('status', ['active', 'enabled', 'provisioned', 'committed'])
                    ->count();

                $offer->forceFill(['active_subscription_count' => $count])->save();

                if ($count > 0) {
                    $this->services->ensureService($offer, true);
                }
            });
    }

    private function syncMicrosoft(Integration $integration, ClientLink $link, SyncRun $run): void
    {
        try {
            $payload = $this->apiFactory->make($integration)->get(
                '/Microsoft/Seats/'.$link->external_customer_id
            );

            foreach ($this->records($payload) as $record) {
                $this->upsert($integration, $link, 'microsoft', $record, $run);
            }
        } catch (CloudFactoryApiException $exception) {
            $this->providerConflict($integration, $link, 'microsoft', $exception);
            $this->progress->conflict($run, 'subscriptions');
        } finally {
            $this->progress->sourceProcessed($run, 'subscriptions');
        }
    }

    private function syncAdobe(Integration $integration, ClientLink $link, SyncRun $run): void
    {
        $page = 1;

        try {
            do {
                $payload = $this->apiFactory->make($integration)->get(
                    '/v1/adobe/customers/'.$link->external_customer_id.'/subscriptions',
                    ['page' => $page, 'pageSize' => 250]
                );

                foreach ($payload['items'] ?? [] as $record) {
                    $this->upsert($integration, $link, 'adobe', $record, $run);
                }

                $totalPages = max(1, (int) ($payload['totalPages'] ?? 1));
                $page++;
            } while ($page <= $totalPages);
        } catch (CloudFactoryApiException $exception) {
            $this->providerConflict($integration, $link, 'adobe', $exception);
            $this->progress->conflict($run, 'subscriptions');
        } finally {
            $this->progress->sourceProcessed($run, 'subscriptions');
        }
    }

    private function upsert(
        Integration $integration,
        ClientLink $link,
        string $provider,
        array $record,
        SyncRun $run,
    ): void {
        $externalId = (string) ($record['subscriptionId'] ?? $record['id'] ?? '');

        if ($externalId === '') {
            $this->progress->itemProcessed($run, 'subscriptions', 'unchanged', false);

            return;
        }

        $productId = (string) ($record['offerId']
            ?? $record['productId']
            ?? $record['catalogItemId']
            ?? $record['sku']
            ?? '');
        $offer = $this->offer($integration, $provider, $productId, $record);
        if (! $offer && $productId !== '') {
            Conflict::query()->firstOrCreate(
                [
                    'integration_id' => $integration->id,
                    'conflict_type' => 'subscription_offer_variant',
                    'external_id' => $externalId,
                    'status' => 'open',
                ],
                ['fields' => ['product_id' => $productId], 'provider_payload' => $record]
            );
            $this->progress->conflict($run, 'subscriptions');
        }
        $existing = Subscription::query()
            ->where('integration_id', $integration->id)
            ->where('provider_family', $provider)
            ->where('external_subscription_id', $externalId)
            ->first();

        $quantity = (int) ($record['currentQuantity'] ?? $record['quantity'] ?? $record['targetQuantity'] ?? 0);
        $status = Str::lower((string) ($record['status'] ?? 'unknown'));
        $service = $offer ? $this->services->ensureService($offer, true) : null;

        $subscription = Subscription::query()->updateOrCreate(
            [
                'integration_id' => $integration->id,
                'provider_family' => $provider,
                'external_subscription_id' => $externalId,
            ],
            [
                'client_link_id' => $link->id,
                'client_id' => $link->client_id,
                'offer_id' => $offer?->id,
                'service_id' => $service?->id,
                'name' => $record['name'] ?? $record['friendlyName'] ?? $offer?->name,
                'quantity' => $quantity,
                'used_quantity' => $record['usedQuantity'] ?? null,
                'status' => $status,
                'auto_renew' => $this->autoRenew($record),
                'commitment_start_date' => $this->date($record, ['commitmentStartDate', 'creationDate', 'startDate']),
                'commitment_end_date' => $this->date($record, ['commitmentEndDate', 'endDate']),
                'renewal_date' => $this->date($record, ['renewalDate', 'renewTime']),
                'cancellation_deadline' => $this->date($record, ['cancellationDeadline']),
                'unit_cost' => $offer?->normalizedCost(),
                'unit_sale_price' => $offer && $service
                    ? $this->services->calculatedSalePrice($offer, $service)
                    : $service?->price_ex_vat,
                'currency' => $offer?->currency ?: (string) ($record['currencyCode'] ?? 'NOK'),
                'origin' => 'cloudfactory',
                'allowed_actions' => $record['allowedActions'] ?? [],
                'provider_payload' => $record,
                'provider_updated_at' => $this->dateTime($record, ['updatedAt', 'lastModifiedDate']),
                'last_synced_at' => now(),
            ]
        );

        $this->applyContract($subscription, $existing);
        $this->confirmOperation($subscription);

        $this->progress->itemProcessed($run, 'subscriptions', $existing ? 'updated' : 'created');
    }

    private function offer(
        Integration $integration,
        string $provider,
        string $productId,
        array $record,
    ): ?Offer {
        if ($productId === '') {
            return null;
        }

        $offer = Offer::query()
            ->where('integration_id', $integration->id)
            ->where('external_product_id', $productId)
            ->first();

        if ($offer) {
            return $offer;
        }

        $recurrenceTerm = $this->providerTerm($record, [
            'recursionTerm', 'RecursionTerm', 'recurrenceTerm', 'termDuration', 'TermDuration',
            'commitmentTerm', 'product.recursionTerm', 'offer.recursionTerm',
        ]);
        $billingTerm = $this->providerTerm($record, [
            'billingTerm', 'BillingTerm', 'billingCycle', 'BillingCycle',
            'product.billingTerm', 'offer.billingTerm',
        ]);
        $candidates = Offer::query()
            ->where('integration_id', $integration->id)
            ->where('sku', $productId)
            ->when($recurrenceTerm !== null, fn ($query) => $query->where('recurrence_term', $recurrenceTerm))
            ->when($billingTerm !== null, fn ($query) => $query->where('billing_term', $billingTerm))
            ->limit(2)
            ->get();

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        if ($candidates->count() > 1) {
            return null;
        }

        $vendorName = ucfirst($provider);
        $vendor = $this->services->vendor($integration, $vendorName);

        return Offer::query()->create([
            'integration_id' => $integration->id,
            'external_product_id' => $productId,
            'sku' => $record['sku'] ?? $record['offerId'] ?? $productId,
            'name' => $record['name'] ?? $record['friendlyName'] ?? $productId,
            'provider_family' => $provider,
            'vendor_name' => $vendorName,
            'vendor_id' => $vendor?->id,
            'recurrence_term' => $recurrenceTerm,
            'billing_term' => $billingTerm,
            'currency' => $record['currencyCode'] ?? 'NOK',
            'sell_enabled' => false,
            'active_subscription_count' => 1,
            'provider_payload' => ['subscription_discovered' => $record],
            'last_synced_at' => now(),
        ]);
    }

    private function providerTerm(array $record, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = data_get($record, $key);
            if ($value === null || $value === '') {
                continue;
            }

            if (is_numeric($value)) {
                return (int) $value;
            }

            $label = Str::lower(trim((string) $value));

            if (Str::contains($label, ['one-time', 'one time', 'once'])) {
                return 0;
            }
            if (Str::contains($label, ['monthly', 'month-to-month'])) {
                return 1;
            }
            if (Str::contains($label, ['triennial', 'three year', '3 year'])) {
                return 36;
            }
            if (Str::contains($label, ['annual', 'yearly', 'one year', '1 year'])) {
                return 12;
            }
        }

        return null;
    }

    private function applyContract(Subscription $subscription, ?Subscription $before): void
    {
        $contract = $this->eligibleContract($subscription);
        $item = $subscription->contract_item_id
            ? ContractItem::query()->find($subscription->contract_item_id)
            : null;

        $item ??= $contract?->items()
            ->where(function ($query) use ($subscription): void {
                $query->where('provider_subscription_id', $subscription->external_subscription_id);

                if ($subscription->service_id) {
                    $query->orWhere(function ($query) use ($subscription): void {
                        $query->where('service_id', $subscription->service_id)
                            ->where(function ($query) use ($subscription): void {
                                $query->where('cloudfactory_offer_id', $subscription->offer_id)
                                    ->orWhereNull('cloudfactory_offer_id');
                            });
                    });
                }
            })
            ->first();

        if (! $contract || ! $item) {
            $subscription->forceFill(['billing_state' => 'unlinked'])->save();
            $this->subscriptionConflict($subscription, 'contract_link', [
                'contract_found' => (bool) $contract,
                'service_id' => $subscription->service_id,
            ]);

            return;
        }

        $oldQuantity = $before?->quantity;
        $newQuantity = $subscription->quantity;
        $oldPrice = $before?->unit_sale_price;
        $newPrice = $subscription->unit_sale_price;
        $quantityAllowed = $this->quantityAllowed($contract, $oldQuantity, $newQuantity);

        if ($oldQuantity !== null && $oldQuantity !== $newQuantity && ! $quantityAllowed) {
            $subscription->forceFill([
                'contract_id' => $contract->id,
                'contract_item_id' => $item->id,
                'billing_state' => 'blocked',
            ])->save();
            $this->subscriptionConflict($subscription, 'contract_quantity', [
                'old_quantity' => $oldQuantity,
                'new_quantity' => $newQuantity,
            ]);

            return;
        }

        $service = $subscription->service_id
            ? Services::with('costRelations.cost')->find($subscription->service_id)
            : null;
        $internalCost = (float) ($service?->costRelations
            ->filter(fn ($relation) => ! ($relation->cost?->managed_externally ?? false))
            ->sum(fn ($relation) => $relation->cost?->cost ?? 0) ?? 0);

        $itemUpdate = [
            'source' => 'cloudfactory',
            'cloudfactory_offer_id' => $subscription->offer_id,
            'cost_unit_price' => (float) ($subscription->unit_cost ?? 0) + $internalCost,
            'cost_currency' => $subscription->currency,
            'provider_subscription_id' => $subscription->external_subscription_id,
            'quantity' => $newQuantity,
            'commitment_start_date' => $subscription->commitment_start_date,
            'commitment_end_date' => $subscription->commitment_end_date,
            'cancellation_deadline' => $subscription->cancellation_deadline,
            'billing_effective_at' => $this->active($subscription->status) ? now() : $item->billing_effective_at,
            'licence_metadata' => [
                'provider_family' => $subscription->provider_family,
                'cloudfactory_subscription_id' => $subscription->id,
                'auto_renew' => $subscription->auto_renew,
                'renewal_date' => $subscription->renewal_date?->toDateString(),
            ],
        ];

        if ($contract->allow_license_price_updates && $newPrice !== null) {
            $itemUpdate['unit_price'] = $newPrice;
        }

        $item->forceFill($itemUpdate)->save();
        $subscription->forceFill([
            'contract_id' => $contract->id,
            'contract_item_id' => $item->id,
            'billing_state' => $this->active($subscription->status) ? 'confirmed' : 'pending',
        ])->save();

        if (! $before || $oldQuantity !== $newQuantity || (string) $oldPrice !== (string) $newPrice) {
            LicenceAmendment::query()->create([
                'subscription_id' => $subscription->id,
                'contract_id' => $contract->id,
                'contract_item_id' => $item->id,
                'change_type' => $before ? 'reconciled_change' : 'import',
                'old_quantity' => $oldQuantity,
                'new_quantity' => $newQuantity,
                'old_unit_price' => $oldPrice,
                'new_unit_price' => $newPrice,
                'commitment_end_date' => $subscription->commitment_end_date,
                'effective_at' => now(),
                'origin' => 'cloudfactory',
                'snapshot' => $subscription->provider_payload,
            ]);
        }

        if ($this->active($subscription->status)) {
            $this->billingPeriod($subscription->refresh());
        }
    }

    private function eligibleContract(Subscription $subscription): ?Contracts
    {
        $today = now()->toDateString();

        return Contracts::query()
            ->where('client_id', $subscription->client_id)
            ->where('approval_status', 'won')
            ->whereDate('start_date', '<=', $today)
            ->where(function ($query) use ($today): void {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })
            ->latest('accepted_at')
            ->latest('id')
            ->first();
    }

    private function quantityAllowed(Contracts $contract, ?int $old, int $new): bool
    {
        if ($old === null || $old === $new) {
            return true;
        }

        if ($new > $old) {
            return (bool) $contract->allow_license_increases;
        }

        return (bool) ($contract->allow_license_decreases || $contract->allow_decrease_during_binding);
    }

    private function billingPeriod(Subscription $subscription): void
    {
        if (! $subscription->contract_item_id || $subscription->unit_sale_price === null) {
            return;
        }

        $period = BillingPeriod::query()
            ->where('subscription_id', $subscription->id)
            ->whereDate('period_start', now()->startOfMonth()->toDateString())
            ->whereDate('period_end', now()->endOfMonth()->toDateString())
            ->first() ?? new BillingPeriod([
                'subscription_id' => $subscription->id,
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
            ]);

        $period->forceFill([
            'client_id' => $subscription->client_id,
            'contract_item_id' => $subscription->contract_item_id,
            'quantity' => $subscription->quantity,
            'unit_price_ex_vat' => $subscription->unit_sale_price,
            'currency' => $subscription->currency,
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'metadata' => ['provider_status' => $subscription->status],
        ])->save();
    }

    private function confirmOperation(Subscription $subscription): void
    {
        Operation::query()
            ->where('integration_id', $subscription->integration_id)
            ->where('client_id', $subscription->client_id)
            ->whereIn('status', ['pending', 'submitted'])
            ->get()
            ->each(function (Operation $operation) use ($subscription): void {
                $productId = data_get($operation->request_payload, 'external_product_id');
                $sameSubscription = $operation->subscription_id === $subscription->id;
                $sameProduct = $productId && $productId === $subscription->offer?->external_product_id;

                if (! $sameSubscription && ! $sameProduct) {
                    return;
                }

                $requested = (int) data_get($operation->request_payload, 'quantity', $subscription->quantity);

                if ($operation->action !== 'issue' && $requested !== $subscription->quantity) {
                    return;
                }

                $operation->forceFill([
                    'subscription_id' => $subscription->id,
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'last_error' => null,
                ])->save();
            });
    }

    private function providerConflict(
        Integration $integration,
        ClientLink $link,
        string $provider,
        CloudFactoryApiException $exception,
    ): void {
        Conflict::query()->updateOrCreate(
            [
                'integration_id' => $integration->id,
                'conflict_type' => 'provider_sync_'.$provider,
                'external_id' => $link->external_customer_id,
                'status' => 'open',
            ],
            [
                'client_id' => $link->client_id,
                'fields' => ['provider_status' => $exception->status],
            ]
        );
    }

    private function subscriptionConflict(Subscription $subscription, string $type, array $fields): void
    {
        Conflict::query()->firstOrCreate(
            [
                'integration_id' => $subscription->integration_id,
                'conflict_type' => $type,
                'external_id' => $subscription->external_subscription_id,
                'status' => 'open',
            ],
            [
                'client_id' => $subscription->client_id,
                'fields' => $fields,
                'provider_payload' => $subscription->provider_payload,
            ]
        );
    }

    private function records(array $payload): array
    {
        if (Arr::isList($payload)) {
            return $payload;
        }

        foreach (['items', 'results', 'subscriptions', 'value'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return [];
    }

    private function autoRenew(array $record): ?bool
    {
        $value = $record['autoRenew'] ?? $record['autoRenewal'] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        return in_array(Str::lower((string) $value), ['true', 'enabled', 'on', 'automatic'], true);
    }

    private function date(array $record, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (filled($record[$key] ?? null)) {
                return Carbon::parse($record[$key])->toDateString();
            }
        }

        return null;
    }

    private function dateTime(array $record, array $keys): ?Carbon
    {
        foreach ($keys as $key) {
            if (filled($record[$key] ?? null)) {
                return Carbon::parse($record[$key]);
            }
        }

        return null;
    }

    private function active(string $status): bool
    {
        return in_array(Str::lower($status), ['active', 'enabled', 'provisioned', 'committed'], true);
    }
}
