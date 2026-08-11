<?php

namespace App\Modules\Documentation\Models;

use App\Modules\Storage\Models\PurchaseOrderImport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Canonical partner register for vendors, manufacturers, and suppliers.
 *
 * Documentation owns this master data, while other modules reference it for
 * assets, costs, inventory items, supplier lines, and purchase workflows.
 */
class Vendor extends Model
{
    protected $table = 'vendors';

    protected $fillable = [
        'name',
        'vendor_code',
        'org_no',
        'url',
        'phone',
        'email',
        'default_lead_time_days',
        'note',
        'terms',
        'is_vendor',
        'is_supplier',
        'is_manufacturer',
        'is_active',
        'created_from_purchase_import_id',
        'supplier_import_identity_hash',
        'supplier_bootstrap_status',
        'source_provenance',
    ];

    protected $casts = [
        'default_lead_time_days' => 'integer',
        'is_vendor' => 'boolean',
        'is_supplier' => 'boolean',
        'is_manufacturer' => 'boolean',
        'is_active' => 'boolean',
        'source_provenance' => 'array',
    ];

    public function services()
    {
        return $this->hasMany(\App\Modules\Commercial\Models\Services\Services::class);
    }

    /**
     * Return the immutable Storage import that originally bootstrapped this supplier.
     */
    public function createdFromPurchaseImport(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderImport::class, 'created_from_purchase_import_id');
    }
}
