<?php

namespace App\Modules\Nextcloud\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NextcloudUserCredential extends Model
{
    protected $fillable = [
        'connection_id',
        'user_id',
        'remote_username',
        'app_password',
        'is_active',
        'status',
        'last_used_at',
        'last_success_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'app_password' => 'encrypted',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(NextcloudConnection::class, 'connection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
