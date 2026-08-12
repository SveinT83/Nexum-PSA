<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesQuoteTemplateAcknowledgement extends Model
{
    protected $fillable = [
        'template_id',
        'template_line_id',
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

    public function template(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteTemplate::class, 'template_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteTemplateLine::class, 'template_line_id');
    }

    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'template_line_id' => $this->template_line_id,
            'title' => $this->title,
            'body' => $this->body,
            'is_required' => $this->is_required,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'sort_order' => $this->sort_order,
        ];
    }
}
