<?php

namespace App\Modules\Integration\Services\CloudFactory;

use App\Models\System\Integrations\Integration;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Integration\Models\CloudFactory\Conflict;
use App\Modules\Integration\Models\CloudFactory\Offer;
use App\Modules\Integration\Models\CloudFactory\VendorLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CloudFactoryVendorResolver
{
    private const MICROSOFT_PRODUCT_TYPES = [
        'NCE',
        'CSP',
        'SPLA',
        'AZURE',
        'MSSOFTWARE',
        'MSPERPETUAL',
    ];

    private const PRODUCT_TYPE_ALIASES = [
        'ACRONIS' => 'Acronis',
        'DROPBOX' => 'Dropbox',
        'HELLOSIGN' => 'Dropbox',
        'ESET' => 'ESET',
        'EXCLAIMER' => 'Exclaimer',
        'KEEPIT' => 'Keepit',
        'RDUNZ' => 'Readynez',
        'IMPOSSIBLECLOUD' => 'Impossible Cloud',
        'TWINGATE' => 'Twingate',
        'ADOBE' => 'Adobe',
    ];

    private const GENERIC_PRODUCT_TYPES = ['IAAS'];

    public function __construct(
        private readonly CloudFactoryAudit $audit,
    ) {}

    /**
     * Persist stable Cloud Factory category identities and map them to canonical Nexum Vendors.
     */
    public function synchronize(Integration $integration, array $categories): Collection
    {
        $links = collect();

        foreach ($categories as $category) {
            $externalId = trim((string) ($category['id'] ?? ''));

            if ($externalId === '') {
                continue;
            }

            $link = VendorLink::query()->firstOrNew([
                'integration_id' => $integration->id,
                'external_category_id' => $externalId,
            ]);
            $link->forceFill([
                'external_name' => trim((string) ($category['name'] ?? $externalId)),
                'external_product_type' => filled($category['productType'] ?? null)
                    ? trim((string) $category['productType'])
                    : null,
                'provider_payload' => $category,
                'last_synced_at' => now(),
            ]);

            if (! ($link->vendor_id && $link->match_method === 'manual')) {
                $resolution = $this->resolveCategory($integration, $category);
                $link->vendor_id = $resolution['vendor']?->id;
                $link->match_method = $resolution['method'];
            }

            $link->save();
            $link->load('vendor');
            $this->propagate($link);
            $this->recordConflictState($link);
            $links->put($externalId, $link);
        }

        return $links;
    }

    public function findOrCreateVendor(Integration $integration, ?string $name): ?Vendor
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        return $this->resolveCanonicalName($name, integration: $integration)['vendor'];
    }

    public function linkManually(VendorLink $link, Vendor $vendor): VendorLink
    {
        $link->forceFill([
            'vendor_id' => $vendor->id,
            'match_method' => 'manual',
        ])->save();
        $link->setRelation('vendor', $vendor);

        $this->propagate($link);
        $this->resolveConflict($link);
        $this->audit->record(
            'catalogue.vendor_linked',
            $link->integration,
            subject: $link,
            metadata: [
                'vendor_id' => $vendor->id,
                'external_category_id' => $link->external_category_id,
                'match_method' => 'manual',
            ]
        );

        return $link->refresh()->load('vendor');
    }

    public function providerFamily(?VendorLink $link): ?string
    {
        if (! $link?->vendor) {
            return null;
        }

        return Str::lower(Str::slug($link->vendor->name, '_'));
    }

    private function resolveCategory(Integration $integration, array $category): array
    {
        $canonicalName = $this->canonicalName($category);

        if ($canonicalName === null) {
            return ['vendor' => null, 'method' => 'unresolved'];
        }

        return $this->resolveCanonicalName(
            $canonicalName,
            strcasecmp($canonicalName, trim((string) ($category['name'] ?? ''))) === 0
                ? null
                : 'alias',
            $integration
        );
    }

    private function resolveCanonicalName(string $canonicalName, ?string $aliasMethod = null, ?Integration $integration = null): array
    {
        $exact = Vendor::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($canonicalName)])
            ->get();

        if ($exact->count() === 1) {
            return [
                'vendor' => $exact->first(),
                'method' => $aliasMethod ? $aliasMethod.'_existing' : 'existing_name',
            ];
        }

        if ($exact->count() > 1) {
            return ['vendor' => null, 'method' => 'ambiguous'];
        }

        $normalized = $this->normalizedName($canonicalName);
        $matches = Vendor::query()->get()->filter(
            fn (Vendor $vendor): bool => $this->normalizedName($vendor->name) === $normalized
        );

        if ($matches->count() === 1) {
            return [
                'vendor' => $matches->first(),
                'method' => $aliasMethod ? $aliasMethod.'_normalized' : 'normalized_name',
            ];
        }

        if ($matches->count() > 1) {
            return ['vendor' => null, 'method' => 'ambiguous'];
        }

        $slug = Str::upper(Str::slug($canonicalName, '')) ?: 'VENDOR';
        $vendor = Vendor::query()->firstOrCreate(
            ['vendor_code' => 'CF-'.substr($slug, 0, 18).'-'.substr(sha1($normalized), 0, 6)],
            [
                'name' => $canonicalName,
                'note' => 'Created from Cloud Factory catalogue synchronization.',
                'is_vendor' => true,
                'is_supplier' => false,
                'is_manufacturer' => true,
                'is_active' => true,
            ]
        );

        if ($vendor->wasRecentlyCreated) {
            $this->audit->record('catalogue.vendor_registered', $integration, metadata: [
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->name,
            ]);
        }

        return ['vendor' => $vendor, 'method' => $aliasMethod ? $aliasMethod.'_created' : 'created'];
    }

    private function canonicalName(array $category): ?string
    {
        $productType = Str::upper(trim((string) ($category['productType'] ?? '')));
        $name = trim((string) ($category['name'] ?? ''));

        if (in_array($productType, self::GENERIC_PRODUCT_TYPES, true)) {
            return null;
        }

        if (in_array($productType, self::MICROSOFT_PRODUCT_TYPES, true)
            || Str::startsWith(Str::lower($name), 'microsoft')) {
            return 'Microsoft';
        }

        return self::PRODUCT_TYPE_ALIASES[$productType] ?? ($name !== '' ? $name : null);
    }

    private function normalizedName(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii(trim($name)))) ?: '';
    }

    private function propagate(VendorLink $link): void
    {
        $offers = Offer::query()
            ->where('integration_id', $link->integration_id)
            ->where('external_category_id', $link->external_category_id)
            ->get();
        $vendorName = $link->vendor?->name;

        foreach ($offers as $offer) {
            $offer->forceFill([
                'vendor_id' => $link->vendor_id,
                'vendor_name' => $vendorName,
            ])->save();

            if ($offer->service_id) {
                Services::query()->whereKey($offer->service_id)->update([
                    'vendor_id' => $link->vendor_id,
                ]);
            }
        }
    }

    private function recordConflictState(VendorLink $link): void
    {
        if ($link->vendor_id) {
            $this->resolveConflict($link);

            return;
        }

        Conflict::query()->updateOrCreate(
            [
                'integration_id' => $link->integration_id,
                'conflict_type' => 'vendor_match',
                'external_id' => $link->external_category_id,
                'status' => 'open',
            ],
            [
                'fields' => [
                    'external_name' => $link->external_name,
                    'external_product_type' => $link->external_product_type,
                    'match_method' => $link->match_method,
                ],
                'provider_payload' => $link->provider_payload,
            ]
        );
    }

    private function resolveConflict(VendorLink $link): void
    {
        Conflict::query()
            ->where('integration_id', $link->integration_id)
            ->where('conflict_type', 'vendor_match')
            ->where('external_id', $link->external_category_id)
            ->where('status', 'open')
            ->update([
                'status' => 'resolved',
                'resolved_by' => auth()->id(),
                'resolved_at' => now(),
            ]);
    }
}
