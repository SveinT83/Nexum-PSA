<?php

namespace App\Modules\Commercial\Models\Terms;

use Illuminate\Database\Eloquent\Model;

class LegalAcceptanceEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'term_version_ids' => 'array',
            'evidence' => 'array',
            'metadata' => 'array',
            'unit_price' => 'decimal:4',
            'confirmed_at' => 'datetime',
        ];
    }
}
