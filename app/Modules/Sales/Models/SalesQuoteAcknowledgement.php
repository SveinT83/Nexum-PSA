<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesQuoteAcknowledgement extends Model
{
    protected $fillable = [
        'quote_version_id',
        'quote_line_id',
        'title',
        'body',
        'is_required',
        'source_type',
        'source_id',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
    ];

    public function quoteVersion(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteVersion::class, 'quote_version_id');
    }

    public function quoteLine(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteLine::class, 'quote_line_id');
    }
}
