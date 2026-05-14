<?php

namespace App\Modules\Storage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warehouse extends Model
{
    use SoftDeletes;

    protected $table = 'storage_warehouses';

    protected $fillable = [
        'name',
        'code',
        'address',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function boxes(): HasMany
    {
        return $this->hasMany(Box::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
