<?php

namespace App\Modules\WorkContext\Models;

use App\Models\Clients\Client;
use App\Modules\WorkContext\Support\WorkContextType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkContext extends Model
{
    protected $fillable = [
        'type',
        'client_id',
        'name',
        'is_default',
        'metadata',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'metadata' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('type', WorkContextType::INTERNAL);
    }

    public function scopeClientContext(Builder $query): Builder
    {
        return $query->where('type', WorkContextType::CLIENT);
    }

    public function isInternal(): bool
    {
        return $this->type === WorkContextType::INTERNAL;
    }

    public function isClient(): bool
    {
        return $this->type === WorkContextType::CLIENT;
    }
}
