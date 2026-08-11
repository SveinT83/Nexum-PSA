<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemVendor extends Model
{
    protected $table = 'storage_item_vendors';

    protected $fillable = [
        'item_id',
        'vendor_id',
        'vendor_sku',
        'created_from_import_line_id',
        'supplier_sku_claim_hash',
        'resolution_method',
        'mapping_provenance',
        'confirmed_by',
        'confirmed_at',
        'purchase_url',
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
        'mapping_provenance' => 'array',
        'confirmed_at' => 'datetime',
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

    public function createdFromImportLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderImportLine::class, 'created_from_import_line_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
