<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailLiveProjectionStream extends Model
{
    public const TYPE_GLOBAL = 'global';

    public const TYPE_ACCOUNT = 'account';

    public const TYPE_USER = 'user';

    protected $table = 'email_live_projection_streams';

    protected $fillable = [
        'stream_type',
        'email_account_id',
        'user_id',
        'global_slot',
        'current_version',
        'oldest_retained_version',
        'acknowledged_version',
        'acknowledged_at',
        'last_changed_at',
    ];

    protected $casts = [
        'email_account_id' => 'integer',
        'user_id' => 'integer',
        'global_slot' => 'integer',
        'current_version' => 'integer',
        'oldest_retained_version' => 'integer',
        'acknowledged_version' => 'integer',
        'acknowledged_at' => 'datetime',
        'last_changed_at' => 'datetime',
    ];

    public function changes(): HasMany
    {
        return $this->hasMany(EmailLiveProjectionChange::class, 'stream_id');
    }
}
