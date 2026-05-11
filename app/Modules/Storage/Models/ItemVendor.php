<?php

namespace App\Modules\Storage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemVendor extends Model
{
    protected $table = 'storage_item_vendors';

    protected $fillable = [
        'item_id',
        'vendor_id',
        'vendor_sku',
        'currency',
        'unit_cost',
        'moq',
        'pack_size',
        'lead_time_days',
        'is_primary',
        'vat_policy',
        'valid_from',
        'valid_to',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'moq' => 'integer',
        'pack_size' => 'integer',
        'lead_time_days' => 'integer',
        'is_primary' => 'boolean',
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
