<?php

namespace App\Modules\Nextcloud\Models;

use App\Models\Clients\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

class NextcloudGroupMapping extends Model
{
    protected $fillable = [
        'connection_id',
        'role_id',
        'client_id',
        'client_role',
        'remote_group_id',
        'remote_group_name',
        'sync_mode',
        'is_managed',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_managed' => 'boolean',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(NextcloudConnection::class, 'connection_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
