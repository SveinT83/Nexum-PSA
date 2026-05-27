<?php

namespace App\Modules\Nextcloud\Models;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class NextcloudConnection extends Model
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_CLIENT = 'client';
    public const SCOPE_SITE = 'site';

    public const MODE_READ_ONLY = 'read_only';
    public const MODE_SYNC = 'sync';
    public const MODE_MANAGED = 'managed';

    protected $fillable = [
        'uuid',
        'name',
        'scope',
        'mode',
        'client_id',
        'client_site_id',
        'is_default',
        'is_active',
        'base_url',
        'admin_url',
        'root_folder',
        'documents_folder',
        'sync_interval_minutes',
        'service_username',
        'service_password',
        'allow_user_credentials',
        'supports_managed_writes',
        'health_status',
        'last_health_check_at',
        'last_successful_sync_at',
        'last_sync_requested_at',
        'last_error',
        'capabilities',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'allow_user_credentials' => 'boolean',
            'supports_managed_writes' => 'boolean',
            'service_password' => 'encrypted',
            'last_health_check_at' => 'datetime',
            'last_successful_sync_at' => 'datetime',
            'last_sync_requested_at' => 'datetime',
            'capabilities' => 'array',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (NextcloudConnection $connection): void {
            $connection->uuid ??= (string) Str::uuid();
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function userCredentials(): HasMany
    {
        return $this->hasMany(NextcloudUserCredential::class, 'connection_id');
    }

    public function folderMappings(): HasMany
    {
        return $this->hasMany(NextcloudFolderMapping::class, 'connection_id');
    }

    public function calendarMappings(): HasMany
    {
        return $this->hasMany(NextcloudCalendarMapping::class, 'connection_id');
    }

    public function userMappings(): HasMany
    {
        return $this->hasMany(NextcloudUserMapping::class, 'connection_id');
    }

    public function groupMappings(): HasMany
    {
        return $this->hasMany(NextcloudGroupMapping::class, 'connection_id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(NextcloudSyncLog::class, 'connection_id');
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(NextcloudSyncConflict::class, 'connection_id');
    }

    public function canWrite(): bool
    {
        return in_array($this->mode, [self::MODE_SYNC, self::MODE_MANAGED], true);
    }

    public function canManageRemote(): bool
    {
        return $this->mode === self::MODE_MANAGED;
    }
}
