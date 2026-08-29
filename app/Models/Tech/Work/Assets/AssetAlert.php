<?php

namespace App\Models\Tech\Work\Assets;

use App\Modules\Integration\Models\RmmAlertOccurrence;
use App\Modules\Integration\Models\RmmAlertWorkItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id',
        'integration_type',
        'external_check_id',
        'external_alert_id',
        'fingerprint',
        'title',
        'message',
        'status',
        'severity',
        'provider_context',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
    ];

    protected $casts = [
        'provider_context' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(RmmAlertOccurrence::class);
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(RmmAlertWorkItem::class);
    }
}
