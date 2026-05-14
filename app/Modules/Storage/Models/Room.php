<?php

namespace App\Modules\Storage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model
{
    use SoftDeletes;

    protected $table = 'storage_rooms';

    protected $fillable = [
        'warehouse_id',
        'name',
        'code',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function boxes(): HasMany
    {
        return $this->hasMany(Box::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
