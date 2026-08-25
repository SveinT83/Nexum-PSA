<?php

namespace App\Modules\Integration\Services\CloudFactory;

use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\Commercial\Models\Cost;
use App\Modules\Commercial\Models\CostRelations;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Integration\Models\CloudFactory\Conflict;
use App\Modules\Integration\Models\CloudFactory\Offer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CloudFactoryServiceManager
{
    public function __construct(
        private readonly CloudFactoryIntegration $settings,
        private readonly CloudFactoryAudit $audit,
        private readonly CloudFactoryVendorResolver $vendors,
    ) {}

    public function vendor(Integration $integration, ?string $name): ?Vendor
    {
        return $this->vendors->findOrCreateVendor($integration, $name);
    }

    public function ensureService(Offer $offer, bool $requiredBySubscription = false): ?Services
    {
        if (! $offer->sell_enabled && ! $requiredBySubscription && ! $offer->service_id) {
            return null;
        }

        if ($offer->excluded && ! $requiredBySubscription && ! $offer->service_id) {
            return null;
        }

        if ($offer->service) {
            $this->syncManagedCost($offer, $offer->service);
            $this->applyPricing($offer, $offer->service);
            $this->linkLegalTerms($offer, $offer->service);

            return $offer->service->refresh();
        }

        $config = $this->settings->config($offer->integration);
        $unitId = (int) ($config['default_unit_id'] ?? 0);
        $actorId = (int) ($config['configured_by'] ?? User::query()->value('id'));

        if ($unitId < 1 || $actorId < 1) {
            $this->conflict($offer, 'service_configuration', [
                'default_unit_id' => $unitId ?: null,
                'configured_by' => $actorId ?: null,
            ]);

            return null;
        }

        $sku = $this->uniqueVariantSku($offer);
        $sameSku = Services::query()->where('sku', $sku)->first();

        if ($sameSku) {
            if ($sameSku->source !== 'cloudfactory') {
                $this->conflict($offer, 'service_sku', ['service_id' => $sameSku->id, 'sku' => $sku]);

                return null;
            }

            $ownedByAnotherOffer = Offer::query()
                ->where('service_id', $sameSku->id)
                ->whereKeyNot($offer->id)
                ->exists();
            if ($ownedByAnotherOffer) {
                $this->conflict($offer, 'service_variant_sku', ['service_id' => $sameSku->id, 'sku' => $sku]);

                return null;
            }

            $service = $sameSku;
        } else {
            $service = Services::query()->create([
                'sku' => $sku,
                'name' => $offer->name,
                'unitId' => $unitId,
                'vendor_id' => $offer->vendor_id,
                'source' => 'cloudfactory',
                'source_integration_id' => $offer->integration_id,
                'managed_externally' => true,
                'status' => $offer->deprecated ? 'Inactive' : 'Active',
                'availability_audience' => 'business',
                'orderable' => $offer->purchasable && ! $offer->deprecated,
                'taxable' => 25,
                'billing_cycle' => $this->billingCycle($offer),
                'price_including_tax' => 0,
                'price_ex_vat' => 0,
                'cost_price' => $offer->normalizedCost(),
                'suggested_sale_price' => $offer->normalizedMsrp(),
                'price_currency' => $offer->currency ?: 'NOK',
                'price_mode' => $this->priceMode($offer),
                'price_markup_percent' => $this->markup($offer),
                'manual_price_override' => $this->priceMode($offer) === 'manual',
                'short_description' => data_get($offer->provider_payload, 'product.description'),
                'created_by_user_id' => $actorId,
                'updated_by_user_id' => $actorId,
            ]);
        }

        $offer->forceFill(['service_id' => $service->id])->save();
        $offer = $offer->refresh();
        $this->syncManagedCost($offer, $service);
        $this->applyPricing($offer, $service);
        $this->linkLegalTerms($offer, $service);

        $this->audit->record(
            'catalogue.service_linked',
            $offer->integration,
            subject: $offer,
            metadata: ['service_id' => $service->id, 'required_by_subscription' => $requiredBySubscription]
        );

        return $service->refresh();
    }

    private function linkLegalTerms(Offer $offer, Services $service): void
    {
        $termIds = $offer->legalTerms()->pluck('terms.id');

        if ($termIds->isNotEmpty()) {
            $service->serviceTerms()->syncWithoutDetaching($termIds->all());
        }
    }

    public function applyPricing(Offer $offer, Services $service): void
    {
        $mode = $this->priceMode($offer);
        $markup = $this->markup($offer);
        $cost = $offer->normalizedCost();
        $msrp = $offer->normalizedMsrp();
        $sale = $this->calculatedSalePrice($offer, $service);

        $update = [
            'vendor_id' => $offer->vendor_id,
            'source' => 'cloudfactory',
            'source_integration_id' => $offer->integration_id,
            'managed_externally' => true,
            'cost_price' => $cost,
            'suggested_sale_price' => $msrp,
            'price_currency' => $offer->currency ?: 'NOK',
            'price_mode' => $mode,
            'price_markup_percent' => $markup,
            'manual_price_override' => $mode === 'manual',
            'status' => $offer->deprecated ? 'Inactive' : 'Active',
            'orderable' => $offer->sell_enabled && $offer->purchasable && ! $offer->deprecated,
            'billing_cycle' => $this->billingCycle($offer),
        ];

        if ($mode !== 'manual' || $offer->manual_sale_price !== null) {
            $update['price_ex_vat'] = $sale;
            $update['price_including_tax'] = (string) BigDecimal::of($sale)
                ->multipliedBy('1.25')
                ->toScale(2, RoundingMode::HALF_UP);
        }

        $service->forceFill($update)->save();
    }

    /**
     * Return the Contract-bound sale price as a canonical two-decimal string.
     * Provider cost/profit storage remains unchanged; only the customer sale
     * price path is normalized and marked up without binary floating point.
     */
    public function calculatedSalePrice(Offer $offer, Services $service): string
    {
        $mode = $this->priceMode($offer);
        $markup = $this->markupDecimal($offer);
        $sale = match ($mode) {
            'manual' => $this->decimal(
                $offer->manual_sale_price ?? $service->price_ex_vat,
                'manual_sale_price',
            ),
            'msrp_markup' => $this->applyMarkup(
                $this->normalizedSaleBasis($offer, $offer->msrp, 'msrp'),
                $markup,
            ),
            'cost_markup' => $this->applyMarkup(
                $this->normalizedSaleBasis($offer, $offer->cost, 'cost'),
                $markup,
            ),
            default => $this->normalizedSaleBasis($offer, $offer->msrp, 'msrp'),
        };

        return (string) $sale->toScale(2, RoundingMode::HALF_UP);
    }

    private function normalizedSaleBasis(Offer $offer, mixed $price, string $field): BigDecimal
    {
        if ($price === null || $price === '') {
            return BigDecimal::zero()->toScale(4);
        }

        $amount = $this->decimal($price, $field);
        $commitmentMonths = (int) $offer->recurrence_term;

        if ($commitmentMonths <= 0) {
            return $amount->toScale(4, RoundingMode::HALF_UP);
        }

        $commercialMonths = match ($offer->commercialBillingInterval()) {
            'monthly' => 1,
            'quarterly' => 3,
            'yearly' => 12,
            default => $commitmentMonths,
        };

        return $amount
            ->multipliedBy($commercialMonths)
            ->dividedBy($commitmentMonths, 4, RoundingMode::HALF_UP);
    }

    private function applyMarkup(BigDecimal $amount, BigDecimal $markup): BigDecimal
    {
        return $amount
            ->multipliedBy(BigDecimal::of(100)->plus($markup))
            ->dividedBy(100);
    }

    private function markupDecimal(Offer $offer): BigDecimal
    {
        return $this->decimal(
            $offer->markup_percent
                ?? data_get($offer->integration->config, 'markup_percent', 0),
            'markup_percent',
        );
    }

    private function decimal(mixed $value, string $field): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }

        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException("{$field} must be a finite decimal value.");
            }

            $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("{$field} must be a decimal string or number.");
        }

        $value = trim($value);
        if (! preg_match('/^[+-]?\\d+(?:\\.\\d+)?$/D', $value)) {
            throw new InvalidArgumentException("{$field} must use an unambiguous decimal value.");
        }

        return BigDecimal::of($value);
    }

    private function syncManagedCost(Offer $offer, Services $service): ?Cost
    {
        $normalizedCost = $offer->normalizedCost();
        if ($normalizedCost === null) {
            return null;
        }

        if (! $offer->vendor_id) {
            $this->conflict($offer, 'cost_vendor', ['service_id' => $service->id]);

            return null;
        }

        $config = $this->settings->config($offer->integration);
        $actorId = (int) ($config['configured_by'] ?? User::query()->value('id'));
        if ($actorId < 1) {
            $this->conflict($offer, 'service_configuration', ['configured_by' => null]);

            return null;
        }

        $terms = collect([$offer->commitmentLabel(), $offer->billingLabel()])
            ->filter()
            ->implode(' / ');
        $cost = Cost::query()->updateOrCreate(
            [
                'source' => 'cloudfactory',
                'external_reference' => $offer->id,
            ],
            [
                'managed_externally' => true,
                'source_integration_id' => $offer->integration_id,
                'name' => Str::limit($offer->name.($terms ? ' ('.$terms.')' : ''), 255, ''),
                'cost' => $normalizedCost,
                'currency' => $offer->currency ?: 'NOK',
                'unitId' => $service->unitId,
                'recurrence' => $offer->commercialCostRecurrence(),
                'vendor_id' => $offer->vendor_id,
                'note' => 'Cloud Factory source offer '.$offer->external_product_id
                    .'; raw term cost '.($offer->cost ?? 'n/a').' '.($offer->currency ?: 'NOK')
                    .'; normalized for '.$offer->commercialBillingInterval().'.',
                'created_by_user_id' => $actorId,
                'updated_by_user_id' => $actorId,
            ]
        );

        if ((int) $offer->cost_id !== (int) $cost->id) {
            $offer->forceFill(['cost_id' => $cost->id])->save();
        }

        CostRelations::query()
            ->where('serviceId', $service->id)
            ->whereIn('costId', Cost::query()
                ->where('managed_externally', true)
                ->where('source_integration_id', $offer->integration_id)
                ->select('id'))
            ->where('costId', '!=', $cost->id)
            ->delete();

        CostRelations::query()->firstOrCreate([
            'serviceId' => $service->id,
            'costId' => $cost->id,
        ]);

        return $cost;
    }

    public function variantSku(Offer $offer): string
    {
        $base = filled($offer->sku)
            ? trim((string) $offer->sku)
            : 'CF-'.Str::upper(substr(str_replace('-', '', $offer->external_product_id), 0, 18));
        $suffix = '-C'.$this->termSkuPart($offer->recurrence_term)
            .'-B'.$this->termSkuPart($offer->billing_term);

        return Str::limit($base, 255 - strlen($suffix), '').$suffix;
    }

    private function uniqueVariantSku(Offer $offer): string
    {
        $sku = $this->variantSku($offer);
        $service = Services::query()->where('sku', $sku)->first();

        if (! $service || ! Offer::query()->where('service_id', $service->id)->whereKeyNot($offer->id)->exists()) {
            return $sku;
        }

        $collisionSuffix = '-'.Str::upper(substr(sha1($offer->id), 0, 8));

        return Str::limit($sku, 255 - strlen($collisionSuffix), '').$collisionSuffix;
    }

    private function termSkuPart(?int $term): string
    {
        return $term === null ? 'X' : (string) $term;
    }

    private function priceMode(Offer $offer): string
    {
        return (string) ($offer->price_mode
            ?: data_get($offer->integration->config, 'pricing_mode', 'follow_msrp'));
    }

    private function markup(Offer $offer): float
    {
        return (float) ($offer->markup_percent
            ?? data_get($offer->integration->config, 'markup_percent', 0));
    }

    private function billingCycle(Offer $offer): string
    {
        return $offer->commercialBillingInterval();
    }

    private function conflict(Offer $offer, string $type, array $fields): void
    {
        Conflict::query()->firstOrCreate(
            [
                'integration_id' => $offer->integration_id,
                'conflict_type' => $type,
                'external_id' => $offer->external_product_id,
                'status' => 'open',
            ],
            [
                'fields' => $fields,
                'provider_payload' => $offer->provider_payload,
            ]
        );
    }
}
