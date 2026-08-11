<?php

namespace App\Modules\Storage\Support;

use App\Modules\Documentation\Models\ShippingCarrier;
use App\Modules\Documentation\Support\ShippingTrackingLinkResolver;

class ShippingCarrierSnapshot
{
    /** @return array<string, mixed> */
    public static function from(?ShippingCarrier $carrier): array
    {
        if (! $carrier) {
            return [
                'carrier_code_snapshot' => null,
                'carrier_name_snapshot' => null,
                'carrier_tracking_method_snapshot' => null,
                'carrier_tracking_url_template_snapshot' => null,
                'carrier_tracking_page_url_snapshot' => null,
                'carrier_allowed_hosts_snapshot' => null,
                'carrier_link_visibility_snapshot' => null,
                'carrier_verification_state_snapshot' => null,
                'carrier_verified_at_snapshot' => null,
            ];
        }

        return [
            'carrier_code_snapshot' => $carrier->code,
            'carrier_name_snapshot' => $carrier->name,
            'carrier_tracking_method_snapshot' => $carrier->tracking_method,
            'carrier_tracking_url_template_snapshot' => $carrier->tracking_url_template,
            'carrier_tracking_page_url_snapshot' => $carrier->tracking_page_url,
            'carrier_allowed_hosts_snapshot' => app(ShippingTrackingLinkResolver::class)
                ->normalizeAllowedHosts($carrier->allowed_tracking_hosts ?? []),
            'carrier_link_visibility_snapshot' => $carrier->link_visibility,
            'carrier_verification_state_snapshot' => $carrier->verification_state,
            'carrier_verified_at_snapshot' => $carrier->verified_at,
        ];
    }
}
