<?php

namespace App\Modules\Commercial\Models\Terms;

use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractTermSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contracts::class);
    }

    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(terms::class);
    }

    public function termVersion(): BelongsTo
    {
        return $this->belongsTo(TermVersion::class);
    }
}
