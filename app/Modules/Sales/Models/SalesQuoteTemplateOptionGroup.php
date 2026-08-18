<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesQuoteTemplateOptionGroup extends Model
{
    protected $fillable = [
        'template_id',
        'name',
        'type',
        'description',
        'min_select',
        'max_select',
        'sort_order',
    ];

    protected $casts = [
        'min_select' => 'integer',
        'max_select' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteTemplate::class, 'template_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesQuoteTemplateLine::class, 'template_option_group_id')->orderBy('sort_order')->orderBy('id');
    }

    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'description' => $this->description,
            'min_select' => $this->min_select,
            'max_select' => $this->max_select,
            'sort_order' => $this->sort_order,
        ];
    }
}
