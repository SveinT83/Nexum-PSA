<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiModelRate extends Model
{
    protected $fillable = [
        'ai_model_rate_card_id',
        'metric',
        'rate',
        'unit_quantity',
    ];

    protected $casts = [
        'rate' => 'decimal:12',
        'unit_quantity' => 'integer',
    ];

    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(AiModelRateCard::class, 'ai_model_rate_card_id');
    }
}
