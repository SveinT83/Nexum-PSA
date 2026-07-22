<?php

namespace App\Modules\Commercial\Models\Terms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TermVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'provider_published_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(terms::class);
    }
}
