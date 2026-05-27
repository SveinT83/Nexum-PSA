<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesQuote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'opportunity_id',
        'quote_key',
        'status',
        'current_version_id',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(SalesOpportunity::class, 'opportunity_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SalesQuoteVersion::class, 'quote_id')->orderByDesc('version_number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteVersion::class, 'current_version_id');
    }
}
