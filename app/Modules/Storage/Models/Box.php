<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Box extends Model
{
    use SoftDeletes;

    protected $table = 'storage_boxes';

    protected $fillable = [
        'uuid',
        'warehouse_id',
        'room_id',
        'code_human',
        'name',
        'barcode_value',
        'barcode_type',
        'status',
        'placement_note',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Box $box) {
            $box->uuid ??= (string) Str::uuid();
            $box->barcode_type ??= 'QR';
            $box->status ??= 'in_stock';
        });

        static::created(function (Box $box) {
            if (!$box->barcode_value) {
                $box->barcode_value = (string) $box->id;
                $box->saveQuietly();
            }
        });
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BoxEvent::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
