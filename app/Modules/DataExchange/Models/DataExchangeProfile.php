<?php

namespace App\Modules\DataExchange\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DataExchangeProfile extends Model
{
    use SoftDeletes;

    public const DIRECTION_EXPORT = 'export';
    public const DIRECTION_IMPORT = 'import';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';

    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
    ];

    public function sources(): HasMany
    {
        return $this->hasMany(DataExchangeProfileSource::class, 'profile_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(DataExchangeProfileField::class, 'profile_id');
    }

    public function filters(): HasMany
    {
        return $this->hasMany(DataExchangeProfileFilter::class, 'profile_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(DataExchangeProfileMapping::class, 'profile_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(DataExchangeRun::class, 'profile_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(DataExchangeFile::class, 'profile_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(DataExchangeSchedule::class, 'profile_id');
    }

    public function deliveryTargets(): HasMany
    {
        return $this->hasMany(DataExchangeDeliveryTarget::class, 'profile_id');
    }

    public function importPreviews(): HasMany
    {
        return $this->hasMany(DataExchangeImportPreview::class, 'profile_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
