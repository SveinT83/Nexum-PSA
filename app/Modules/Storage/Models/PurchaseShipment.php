<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use App\Modules\Documentation\Models\ShippingCarrier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseShipment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'storage_purchase_shipments';

    protected $fillable = [
        'purchase_order_id',
        'shipping_carrier_id',
        'reference',
        'status',
        'carrier_code_snapshot',
        'carrier_name_snapshot',
        'carrier_tracking_method_snapshot',
        'carrier_tracking_url_template_snapshot',
        'carrier_tracking_page_url_snapshot',
        'carrier_allowed_hosts_snapshot',
        'carrier_link_visibility_snapshot',
        'carrier_verification_state_snapshot',
        'carrier_verified_at_snapshot',
        'shipped_at',
        'expected_at',
        'delivered_at',
        'status_changed_at',
        'status_changed_by',
        'notes',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'carrier_allowed_hosts_snapshot' => 'array',
        'carrier_verified_at_snapshot' => 'date',
        'shipped_at' => 'datetime',
        'expected_at' => 'datetime',
        'delivered_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class, 'shipping_carrier_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseShipmentLine::class);
    }

    public function trackings(): HasMany
    {
        return $this->hasMany(PurchaseShipmentTracking::class)->orderBy('sort_order')->orderBy('id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_IN_TRANSIT,
            self::STATUS_DELIVERED,
            self::STATUS_PARTIALLY_RECEIVED,
            self::STATUS_RECEIVED,
            self::STATUS_CANCELLED,
        ];
    }
}
