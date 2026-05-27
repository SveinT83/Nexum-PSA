<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use SoftDeletes;

    protected $table = 'storage_items';

    protected $fillable = [
        'warehouse_id',
        'room_id',
        'box_id',
        'primary_vendor_id',
        'manufacturer_vendor_id',
        'sku',
        'name',
        'short_description',
        'long_description',
        'manufacturer',
        'manufacturer_part_number',
        'ean_number',
        'purchase_price',
        'markup_percent',
        'sale_price',
        'vat_rate',
        'has_serials',
        'track_batch',
        'expiry_enabled',
        'becomes_asset',
        'default_warranty_months',
        'reorder_point',
        'target_level',
        'lead_time_days',
        'moq',
        'qty_on_hand',
        'qty_reserved',
        'should_order',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'markup_percent' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'has_serials' => 'boolean',
        'track_batch' => 'boolean',
        'expiry_enabled' => 'boolean',
        'becomes_asset' => 'boolean',
        'should_order' => 'boolean',
        'qty_on_hand' => 'integer',
        'qty_reserved' => 'integer',
        'reorder_point' => 'integer',
        'target_level' => 'integer',
        'lead_time_days' => 'integer',
        'moq' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function primaryVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'primary_vendor_id');
    }

    public function manufacturerVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'manufacturer_vendor_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }

    public function stockUnits(): HasMany
    {
        return $this->hasMany(StockUnit::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function itemVendors(): HasMany
    {
        return $this->hasMany(ItemVendor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getQtyAvailableAttribute(): int
    {
        return max(0, $this->qty_on_hand - $this->qty_reserved);
    }

    public function getNeedsReorderAttribute(): bool
    {
        return $this->should_order
            || $this->qty_on_hand <= 0
            || $this->qty_reserved >= $this->qty_on_hand
            || ($this->reorder_point > 0 && $this->qty_on_hand <= $this->reorder_point);
    }

    public function getSuggestedOrderQtyAttribute(): int
    {
        $shortage = max(0, $this->qty_reserved - $this->qty_on_hand);
        $target = max($this->target_level, $this->reorder_point);
        $suggested = max(0, $target - $this->qty_on_hand + $shortage);

        if ($suggested === 0 && $this->needs_reorder) {
            $suggested = max(1, $this->moq);
        }

        return max($suggested, 0);
    }
}
