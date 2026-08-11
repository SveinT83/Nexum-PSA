<?php

namespace Database\Seeders;

use App\Modules\Documentation\Models\ShippingCarrier;
use Illuminate\Database\Seeder;

class ShippingCarrierSeeder extends Seeder
{
    /**
     * Add a conservative Norway-oriented carrier library without overwriting
     * configuration that an administrator has already reviewed or changed.
     */
    public function run(): void
    {
        foreach ($this->carriers() as $code => $carrier) {
            ShippingCarrier::query()->firstOrCreate(
                ['code' => $code],
                array_replace([
                    'lifecycle_state' => ShippingCarrier::LIFECYCLE_ACTIVE,
                    'sort_order' => 100,
                    'service_tags' => ['parcel'],
                    'support_url' => null,
                    'tracking_method' => ShippingCarrier::TRACKING_GENERIC_PAGE,
                    'tracking_url_template' => null,
                    'allowed_tracking_hosts' => [],
                    'link_visibility' => ShippingCarrier::VISIBILITY_NORMAL,
                    'connector_type' => null,
                    'verification_state' => ShippingCarrier::VERIFICATION_VERIFIED,
                    'verified_at' => '2026-08-05',
                    'notes' => 'Seeded starting point. Reverify carrier URLs before changing verified tracking templates.',
                ], $carrier),
            );
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function carriers(): array
    {
        return [
            'posten' => [
                'name' => 'Posten',
                'legal_name' => 'Posten Bring AS',
                'sort_order' => 10,
                'service_tags' => ['domestic', 'parcel', 'mail'],
                'website_url' => 'https://www.posten.no/',
                'support_url' => 'https://www.posten.no/kundeservice',
                'tracking_page_url' => 'https://sporing.posten.no/sporing/',
                'tracking_method' => ShippingCarrier::TRACKING_TEMPLATE,
                'tracking_url_template' => 'https://sporing.posten.no/sporing/{tracking_number}',
                'allowed_tracking_hosts' => ['posten.no'],
                'source_url' => 'https://sporing.posten.no/sporing/',
            ],
            'bring' => [
                'name' => 'Bring',
                'legal_name' => 'Posten Bring AS',
                'sort_order' => 20,
                'service_tags' => ['domestic', 'international', 'parcel', 'freight'],
                'website_url' => 'https://www.bring.no/',
                'support_url' => 'https://www.bring.no/kundeservice',
                'tracking_page_url' => 'https://tracking.bring.com/tracking/',
                'tracking_method' => ShippingCarrier::TRACKING_TEMPLATE,
                'tracking_url_template' => 'https://tracking.bring.com/tracking/{tracking_number}',
                'allowed_tracking_hosts' => ['bring.com', 'bring.no'],
                'source_url' => 'https://tracking.bring.com/tracking/',
            ],
            'postnord' => [
                'name' => 'PostNord',
                'legal_name' => 'PostNord AS',
                'sort_order' => 30,
                'service_tags' => ['domestic', 'nordic', 'parcel', 'freight'],
                'website_url' => 'https://www.postnord.no/',
                'support_url' => 'https://www.postnord.no/kundeservice/',
                'tracking_page_url' => 'https://www.postnord.no/pakkesporing',
                'allowed_tracking_hosts' => ['postnord.no'],
                'source_url' => 'https://www.postnord.no/pakkesporing',
            ],
            'dhl-express' => [
                'name' => 'DHL Express',
                'legal_name' => 'DHL Express (Norway) AS',
                'sort_order' => 40,
                'service_tags' => ['international', 'express', 'parcel'],
                'website_url' => 'https://www.dhl.com/no-no/home/ekspress.html',
                'support_url' => 'https://www.dhl.com/no-no/home/kundeservice.html',
                'tracking_page_url' => 'https://www.dhl.com/no-no/home/tracking.html',
                'allowed_tracking_hosts' => ['dhl.com'],
                'source_url' => 'https://www.dhl.com/no-no/home/tracking.html',
            ],
            'dhl-ecommerce' => [
                'name' => 'DHL eCommerce',
                'sort_order' => 41,
                'service_tags' => ['international', 'ecommerce', 'parcel'],
                'website_url' => 'https://www.dhl.com/no-no/home/ecommerce.html',
                'tracking_page_url' => 'https://www.dhl.com/no-no/home/tracking.html',
                'allowed_tracking_hosts' => ['dhl.com'],
                'source_url' => 'https://www.dhl.com/no-no/home/tracking.html',
            ],
            'dhl-freight' => [
                'name' => 'DHL Freight',
                'sort_order' => 42,
                'service_tags' => ['international', 'road', 'freight'],
                'website_url' => 'https://www.dhl.com/no-no/home/frakt.html',
                'tracking_page_url' => 'https://www.dhl.com/no-no/home/tracking.html',
                'allowed_tracking_hosts' => ['dhl.com'],
                'source_url' => 'https://www.dhl.com/no-no/home/tracking.html',
            ],
            'dhl-global-forwarding' => [
                'name' => 'DHL Global Forwarding',
                'sort_order' => 43,
                'service_tags' => ['international', 'air', 'ocean', 'freight'],
                'website_url' => 'https://www.dhl.com/no-no/home/global-forwarding.html',
                'tracking_page_url' => 'https://www.dhl.com/no-no/home/tracking.html',
                'allowed_tracking_hosts' => ['dhl.com'],
                'link_visibility' => ShippingCarrier::VISIBILITY_AUTHENTICATED,
                'source_url' => 'https://www.dhl.com/no-no/home/innlogging.html',
            ],
            'ups' => [
                'name' => 'UPS',
                'legal_name' => 'UPS Norway AS',
                'sort_order' => 50,
                'service_tags' => ['international', 'express', 'parcel', 'freight'],
                'website_url' => 'https://www.ups.com/no/no/home',
                'support_url' => 'https://www.ups.com/no/no/support',
                'tracking_page_url' => 'https://www.ups.com/no/no/track',
                'allowed_tracking_hosts' => ['ups.com'],
                'source_url' => 'https://www.ups.com/no/no/support/tracking-support',
            ],
            'fedex' => [
                'name' => 'FedEx',
                'sort_order' => 60,
                'service_tags' => ['international', 'express', 'parcel', 'freight'],
                'website_url' => 'https://www.fedex.com/no-no/home.html',
                'support_url' => 'https://www.fedex.com/no-no/customer-support.html',
                'tracking_page_url' => 'https://www.fedex.com/no-no/tracking.html',
                'allowed_tracking_hosts' => ['fedex.com'],
                'source_url' => 'https://www.fedex.com/no-no/tracking.html',
            ],
            'dsv' => [
                'name' => 'DSV',
                'sort_order' => 70,
                'service_tags' => ['domestic', 'international', 'road', 'air', 'ocean', 'freight'],
                'website_url' => 'https://www.dsv.com/nb-no/',
                'tracking_page_url' => 'https://www.dsv.com/nb-no/support/ordliste/trackandtrace',
                'allowed_tracking_hosts' => ['dsv.com'],
                'link_visibility' => ShippingCarrier::VISIBILITY_AUTHENTICATED,
                'source_url' => 'https://www.dsv.com/nb-no/support/ordliste/trackandtrace',
            ],
            'db-schenker' => [
                'name' => 'DB Schenker',
                'lifecycle_state' => ShippingCarrier::LIFECYCLE_LEGACY,
                'sort_order' => 71,
                'service_tags' => ['legacy', 'domestic', 'international', 'freight'],
                'website_url' => 'https://www.dbschenker.com/no-no/',
                'tracking_page_url' => 'https://www.dbschenker.com/no-no/bedrift/sporing',
                'allowed_tracking_hosts' => ['dbschenker.com'],
                'link_visibility' => ShippingCarrier::VISIBILITY_AUTHENTICATED,
                'source_url' => 'https://www.dsv.com/nb-no/support/ordliste/trackandtrace',
                'notes' => 'Legacy transition profile retained for existing Schenker references after the DSV transition.',
            ],
            'gls' => [
                'name' => 'GLS',
                'sort_order' => 80,
                'service_tags' => ['international', 'parcel'],
                'website_url' => 'https://gls-group.com/GROUP/en/home/',
                'tracking_page_url' => 'https://gls-group.com/GROUP/en/parcel-tracking.html',
                'allowed_tracking_hosts' => ['gls-group.com'],
                'source_url' => 'https://gls-group.com/GROUP/en/parcel-tracking.html',
            ],
            'helthjem' => [
                'name' => 'Helthjem',
                'sort_order' => 90,
                'service_tags' => ['domestic', 'parcel', 'last-mile'],
                'website_url' => 'https://helthjem.no/',
                'support_url' => 'https://helthjem.no/kundeservice',
                'tracking_page_url' => 'https://helthjem.no/sporing',
                'allowed_tracking_hosts' => ['helthjem.no'],
                'source_url' => 'https://helthjem.no/sporing',
            ],
            'instabox' => [
                'name' => 'Instabox',
                'sort_order' => 100,
                'service_tags' => ['domestic', 'parcel', 'locker', 'last-mile'],
                'website_url' => 'https://instabox.io/no-no/',
                'tracking_page_url' => 'https://instabox.io/no-no/track',
                'allowed_tracking_hosts' => ['instabox.io'],
                'link_visibility' => ShippingCarrier::VISIBILITY_RECIPIENT_ONLY,
                'source_url' => 'https://instabox.io/no-no/track',
            ],
            'porterbuddy' => [
                'name' => 'Porterbuddy',
                'sort_order' => 110,
                'service_tags' => ['domestic', 'parcel', 'same-day', 'last-mile'],
                'website_url' => 'https://www.porterbuddy.com/',
                'tracking_page_url' => 'https://www.porterbuddy.com/',
                'tracking_method' => ShippingCarrier::TRACKING_PROVIDER_GENERATED,
                'allowed_tracking_hosts' => ['porterbuddy.com'],
                'link_visibility' => ShippingCarrier::VISIBILITY_RECIPIENT_ONLY,
                'verification_state' => ShippingCarrier::VERIFICATION_NEEDS_REVIEW,
                'verified_at' => null,
                'source_url' => 'https://www.porterbuddy.com/',
                'notes' => 'Use the recipient-specific provider link when supplied; no stable public number template is seeded.',
            ],
            'budbee' => [
                'name' => 'Budbee',
                'lifecycle_state' => ShippingCarrier::LIFECYCLE_INACTIVE,
                'sort_order' => 120,
                'service_tags' => ['legacy', 'parcel', 'locker', 'last-mile'],
                'website_url' => 'https://budbee.com/',
                'tracking_page_url' => 'https://budbee.com/',
                'tracking_method' => ShippingCarrier::TRACKING_PROVIDER_GENERATED,
                'allowed_tracking_hosts' => ['budbee.com'],
                'link_visibility' => ShippingCarrier::VISIBILITY_RECIPIENT_ONLY,
                'source_url' => 'https://budbee.com/',
                'notes' => 'Inactive historical brand profile. Prefer the current Instabee/Instabox route for new setup.',
            ],
        ];
    }
}
