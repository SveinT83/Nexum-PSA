<?php

namespace App\Models\Tech\Work\Assets;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
