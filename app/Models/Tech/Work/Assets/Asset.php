<?php

namespace App\Models\Tech\Work\Assets;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\System\Integrations\ClientRmmLink;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asset extends Model
{
    /** @use HasFactory<\Database\Factories\Assets\AssetFactory> */
    use HasFactory;

    public const TYPE_SERVER = 'server';
    public const TYPE_PC = 'pc';
    public const TYPE_LAPTOP = 'laptop';
    public const TYPE_SWITCH = 'switch';
    public const TYPE_AP = 'ap';
    public const TYPE_FIREWALL = 'firewall';
    public const TYPE_MOBILE = 'mobile';
    public const TYPE_OTHER = 'other';

    public const SENSITIVITY_LOW = 'low';
    public const SENSITIVITY_MEDIUM = 'medium';
    public const SENSITIVITY_HIGH = 'high';
    public const SENSITIVITY_ULTRA = 'ultra';

    public const CRITICALITY_LOW = 'low';
    public const CRITICALITY_MEDIUM = 'medium';
    public const CRITICALITY_HIGH = 'high';
    public const CRITICALITY_CRITICAL = 'critical';

    public const SENSITIVITY_LEVELS = [
        self::SENSITIVITY_LOW,
        self::SENSITIVITY_MEDIUM,
        self::SENSITIVITY_HIGH,
        self::SENSITIVITY_ULTRA,
    ];

    public const CRITICALITY_LEVELS = [
        self::CRITICALITY_LOW,
        self::CRITICALITY_MEDIUM,
        self::CRITICALITY_HIGH,
        self::CRITICALITY_CRITICAL,
    ];

    protected $fillable = [
        'client_id',
        'site_id',
        'user_id',
        'name',
        'type',
        'vendor',
        'vendor_id',
        'model',
        'serial_number',
        'mac_address',
        'ip_address',
        'ip_type',
        'hostname',
        'source',
        'is_managed',
        'status',
        'last_seen_at',
        'metadata',
        'sensitivity_level',
        'criticality_level',
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
        return $this->belongsTo(\App\Modules\Documentation\Models\Vendor::class, 'vendor_id');
    }

    /**
     * Get all RMM links for the asset.
     */
    public function rmmLinks()
    {
        return $this->morphMany(ClientRmmLink::class, 'linkable');
    }

    public function alerts()
    {
        return $this->hasMany(AssetAlert::class);
    }
}
