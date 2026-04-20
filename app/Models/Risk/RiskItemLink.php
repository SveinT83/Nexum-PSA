<?php

namespace App\Models\Risk;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * RiskItemLink provides a flexible (polymorphic) way to connect a risk item to other project entities.
 * For example, a risk can be linked to a documentation entry, a ticket, or an asset.
 */
class RiskItemLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'risk_item_id',
        'linkable_type',
        'linkable_id',
    ];

    /**
     * Get the risk item that this link belongs to.
     */
    public function riskItem(): BelongsTo
    {
        return $this->belongsTo(RiskItem::class);
    }

    /**
     * Get the linkable entity (e.g., Document, Asset, etc.).
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
