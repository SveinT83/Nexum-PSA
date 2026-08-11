<?php

namespace App\Modules\Storage\Models;

use App\Modules\Documentation\Models\ShippingCarrier;
use App\Modules\Documentation\Support\ShippingTrackingLinkResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseShipmentTracking extends Model
{
    protected $table = 'storage_purchase_shipment_trackings';

    protected $fillable = [
        'purchase_shipment_id',
        'shipping_carrier_id',
        'tracking_number',
        'tracking_type',
        'label',
        'direct_url',
        'carrier_code_snapshot',
        'carrier_name_snapshot',
        'carrier_tracking_method_snapshot',
        'carrier_tracking_url_template_snapshot',
        'carrier_tracking_page_url_snapshot',
        'carrier_allowed_hosts_snapshot',
        'carrier_link_visibility_snapshot',
        'carrier_verification_state_snapshot',
        'carrier_verified_at_snapshot',
        'sort_order',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'carrier_allowed_hosts_snapshot' => 'array',
        'carrier_verified_at_snapshot' => 'date',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(PurchaseShipment::class, 'purchase_shipment_id');
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class, 'shipping_carrier_id');
    }

    public function getTrackingUrlAttribute(): ?string
    {
        return app(ShippingTrackingLinkResolver::class)->resolve(
            $this->tracking_number,
            $this->direct_url,
            $this->carrier_tracking_method_snapshot === ShippingCarrier::TRACKING_TEMPLATE
                ? $this->carrier_tracking_url_template_snapshot
                : null,
            $this->carrier_tracking_page_url_snapshot,
            $this->carrier_allowed_hosts_snapshot ?? [],
            $this->carrier_verification_state_snapshot === ShippingCarrier::VERIFICATION_VERIFIED,
        );
    }

    public function getTrackingLinkNoticeAttribute(): ?string
    {
        return match ($this->carrier_link_visibility_snapshot) {
            ShippingCarrier::VISIBILITY_RECIPIENT_ONLY => 'Recipient details may be required',
            ShippingCarrier::VISIBILITY_AUTHENTICATED => 'Carrier sign-in required',
            default => null,
        };
    }
}
