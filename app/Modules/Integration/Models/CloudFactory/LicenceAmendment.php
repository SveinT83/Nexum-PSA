<?php

namespace App\Modules\Integration\Models\CloudFactory;

use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenceAmendment extends CloudFactoryModel
{
    protected $table = 'cloudfactory_licence_amendments';

    protected function casts(): array
    {
        return [
            'old_unit_price' => 'decimal:4',
            'new_unit_price' => 'decimal:4',
            'commitment_end_date' => 'date',
            'effective_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contracts::class);
    }

    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
