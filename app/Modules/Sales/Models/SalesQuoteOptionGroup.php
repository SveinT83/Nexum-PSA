<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesQuoteOptionGroup extends Model
{
    public const TYPES = [
        'required' => 'Required group',
        'optional' => 'Optional add-ons',
        'alternative' => 'Alternatives',
        'good_better_best' => 'Good / Better / Best',
    ];

    protected $fillable = [
        'quote_version_id',
        'name',
        'type',
        'description',
        'min_select',
        'max_select',
        'sort_order',
    ];

    public function quoteVersion(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteVersion::class, 'quote_version_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesQuoteLine::class, 'option_group_id')->orderBy('sort_order')->orderBy('id');
    }
}
