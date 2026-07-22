<?php

namespace App\Modules\Integration\Services\CloudFactory;

use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Models\CloudFactory\Offer;
use App\Modules\Integration\Models\CloudFactory\SyncRun;
use App\Modules\Integration\Models\CloudFactory\VendorLink;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CloudFactoryCatalogueSync
{
    public function __construct(
        private readonly CloudFactoryApiFactory $apiFactory,
        private readonly CloudFactoryServiceManager $services,
        private readonly CloudFactoryVendorResolver $vendors,
        private readonly CloudFactorySyncProgress $progress,
        private readonly CloudFactoryLegalTermsSync $legalTerms,
    ) {}

    public function run(Integration $integration, SyncRun $run): void
    {
        $categories = $this->apiFactory->make($integration)->get('/v2/catalogue/categories');
        $categoryRecords = data_get($categories, 'results', $categories);
        $vendorLinks = $this->vendors->synchronize($integration, is_array($categoryRecords) ? $categoryRecords : []);
        $this->syncCatalogue($integration, $run, $vendorLinks);

        if ($this->apiFactory->make($integration)->hasRole('Adobe')) {
            $adobeLink = $this->vendors->synchronize($integration, [[
                'id' => 'adobe',
                'name' => 'Adobe',
                'productType' => 'ADOBE',
                'source' => 'adobe-products',
            ]])->first();
            $this->syncAdobe(
                $integration,
                $run,
                $adobeLink instanceof VendorLink ? $adobeLink : null
            );
        }
    }

    private function syncCatalogue(Integration $integration, SyncRun $run, Collection $vendorLinks): void
    {
        $api = $this->apiFactory->make($integration);
        $page = 1;

        do {
            $payload = $api->get('/v2/catalogue/products', [
                'pageIndex' => $page,
                'pageSize' => 250,
                'includePrice' => 'true',
                'filter.includeDeprecated' => 'true',
            ]);

            if ($page === 1) {
                $this->progress->addTotal(
                    $run,
                    'catalogue',
                    $this->progress->totalFromPayload($payload, 'results')
                );
            }

            foreach ($payload['results'] ?? [] as $record) {
                $product = $record['product'] ?? [];
                $price = $record['price'] ?? [];
                $vendorLink = $vendorLinks->get((string) ($product['categoryId'] ?? ''));
                $providerFamily = $this->vendors->providerFamily($vendorLink) ?: $this->providerFamily($product);
                $this->upsert(
                    $integration,
                    (string) ($product['id'] ?? ''),
                    $product,
                    $price,
                    $providerFamily,
                    $vendorLink instanceof VendorLink ? $vendorLink : null,
                    $run
                );
            }

            $totalPages = max(1, (int) data_get($payload, 'metadata.totalPages', 1));
            $page++;
        } while ($page <= $totalPages);
    }

    private function syncAdobe(Integration $integration, SyncRun $run, ?VendorLink $vendorLink): void
    {
        $api = $this->apiFactory->make($integration);
        $page = 1;

        do {
            $payload = $api->get('/v1/adobe/products', [
                'page' => $page,
                'pageSize' => 250,
                'includeDeprecated' => 'true',
                'partnerId' => data_get($integration->config, 'partner.id'),
            ]);

            if ($page === 1) {
                $this->progress->addTotal(
                    $run,
                    'catalogue',
                    $this->progress->totalFromPayload($payload, 'items')
                );
            }

            foreach ($payload['items'] ?? [] as $product) {
                $this->upsert(
                    $integration,
                    (string) ($product['offerId'] ?? ''),
                    [
                        'id' => $product['offerId'] ?? null,
                        'sku' => $product['offerId'] ?? null,
                        'categoryId' => 'adobe',
                        'name' => trim(implode(' - ', array_filter([
                            $product['productFamily'] ?? null,
                            $product['levelDetail'] ?? null,
                            $product['users'] ?? null,
                        ]))),
                        'description' => $product['additionalDetail'] ?? null,
                        'attributes' => $product,
                        'deprecated' => false,
                        'isPurchasable' => true,
                    ],
                    [
                        'cost' => $product['cost'] ?? null,
                        'sale' => $product['msrp'] ?? null,
                        'currency' => $product['currency'] ?? 'NOK',
                    ],
                    'adobe',
                    $vendorLink,
                    $run
                );
            }

            $totalPages = max(1, (int) ($payload['totalPages'] ?? 1));
            $page++;
        } while ($page <= $totalPages);
    }

    private function upsert(
        Integration $integration,
        string $externalId,
        array $product,
        array $price,
        ?string $providerFamily,
        ?VendorLink $vendorLink,
        SyncRun $run,
    ): void {
        if ($externalId === '') {
            $this->progress->itemProcessed($run, 'catalogue', 'unchanged', false);

            return;
        }

        // A known category always owns the mapping decision. In particular, do not
        // guess a Vendor for generic or ambiguous categories awaiting manual review.
        $vendorName = $vendorLink
            ? $vendorLink->vendor?->name
            : $this->vendorName($product, $providerFamily);
        $vendor = $vendorLink?->vendor
            ?? ($vendorLink ? null : $this->services->vendor($integration, $vendorName));
        $vendorName = $vendor?->name;
        $existing = Offer::query()
            ->where('integration_id', $integration->id)
            ->where('external_product_id', $externalId)
            ->first();

        $offer = Offer::query()->updateOrCreate(
            [
                'integration_id' => $integration->id,
                'external_product_id' => $externalId,
            ],
            [
                'sku' => $product['sku'] ?? $externalId,
                'name' => $product['name'] ?: ($product['sku'] ?? $externalId),
                'provider_family' => $providerFamily,
                'vendor_name' => $vendorName,
                'vendor_id' => $vendor?->id,
                'external_category_id' => $product['categoryId'] ?? null,
                'recurrence_term' => $product['recursionTerm'] ?? null,
                'billing_term' => $product['billingTerm'] ?? null,
                'cost' => $price['cost'] ?? null,
                'msrp' => $price['sale'] ?? null,
                'currency' => strtoupper((string) ($price['currency'] ?? data_get($integration->config, 'default_currency', 'NOK'))),
                'deprecated' => (bool) ($product['deprecated'] ?? false),
                'purchasable' => $this->purchasable($product['isPurchasable'] ?? true),
                'provider_payload' => ['product' => $product, 'price' => $price],
                'last_synced_at' => now(),
            ]
        );

        if ($offer->sell_enabled || $offer->service_id || $offer->active_subscription_count > 0) {
            $this->services->ensureService($offer, $offer->active_subscription_count > 0);
        }

        $this->legalTerms->syncOffer($offer->refresh(), $product);

        $this->progress->itemProcessed($run, 'catalogue', $existing ? 'updated' : 'created');
    }

    private function vendorName(array $product, ?string $providerFamily): ?string
    {
        $flat = Arr::dot($product['attributes'] ?? []);

        foreach (['vendor', 'vendorName', 'manufacturer', 'publisher', 'brand'] as $needle) {
            foreach ($flat as $key => $value) {
                if (Str::endsWith(Str::lower($key), Str::lower($needle)) && is_string($value) && filled($value)) {
                    return trim($value);
                }
            }
        }

        return match ($providerFamily) {
            'microsoft' => 'Microsoft',
            'adobe' => 'Adobe',
            'keepit' => 'Keepit',
            'exclaimer' => 'Exclaimer',
            default => null,
        };
    }

    private function providerFamily(array $product): ?string
    {
        $haystack = Str::lower(json_encode([
            $product['name'] ?? null,
            $product['sku'] ?? null,
            $product['tags'] ?? [],
            $product['attributes'] ?? [],
        ]) ?: '');

        foreach (['microsoft', 'adobe', 'keepit', 'exclaimer'] as $provider) {
            if (Str::contains($haystack, $provider)) {
                return $provider;
            }
        }

        return null;
    }

    private function purchasable(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return ! in_array(Str::lower((string) $value), ['false', 'none', 'notpurchasable', 'unavailable'], true);
    }
}
