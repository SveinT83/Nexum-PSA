<?php

namespace App\Models\Tech\Work\Assets;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asset extends Model
{
    /** @use HasFactory<\Database\Factories\Assets\AssetFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'site_id',
        'user_id',
        'name',
        'type',
        'vendor_id',
        'model',
        'serial_number',
        'mac_address',
        'ip_address',
        'ip_type',
        'hostname',
        'source',
        'rmm_id',
        'is_managed',
        'status',
        'last_seen_at',
        'metadata',
    ];

    protected $casts = [
        'is_managed' => 'boolean',
        'last_seen_at' => 'datetime',
        'metadata' => 'json',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'site_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Clients\ClientUser::class, 'user_id');
    }

    public function vendorRelation(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Doc\Vendor::class, 'vendor_id');
    }
}
