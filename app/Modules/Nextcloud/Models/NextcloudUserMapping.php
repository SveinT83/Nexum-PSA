<?php

namespace App\Modules\Nextcloud\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NextcloudUserMapping extends Model
{
    protected $fillable = [
        'connection_id',
        'user_id',
        'remote_user_id',
        'remote_username',
        'remote_email',
        'identity_type',
        'identity_model_type',
        'identity_model_id',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
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

    public function identity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'identity_model_type', 'identity_model_id');
    }
}
