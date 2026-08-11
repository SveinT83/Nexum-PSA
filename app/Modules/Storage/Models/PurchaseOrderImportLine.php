<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderImportLine extends Model
{
    public const MAPPING_UNRESOLVED = 'unresolved';

    public const MAPPING_RESOLVED = 'resolved';

    public const MAPPING_AMBIGUOUS = 'ambiguous';

    public const MAPPING_CREATED = 'created';

    public const MAPPING_REVIEW = 'review';

    protected $table = 'storage_purchase_order_import_lines';

    protected $fillable = [
        'import_id',
        'position',
        'source_row_identifier',
        'supplier_sku',
        'normalized_supplier_sku',
        'description',
        'quantity',
        'unit_price',
        'line_total',
        'tax_rate',
        'currency',
        'evidence',
        'extracted_fields',
        'field_confidence',
        'item_id',
        'mapping_status',
        'resolution_method',
        'warnings',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'line_total' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'evidence' => 'array',
        'extracted_fields' => 'array',
        'field_confidence' => 'array',
        'warnings' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderImport::class, 'import_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
