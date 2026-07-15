<?php

namespace App\Modules\DataExchange\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DataExchangeDeliveryTarget extends Model
{
    use SoftDeletes;

    public const TYPE_LOCAL = 'local';
    public const TYPE_FTP = 'ftp';
    public const TYPE_SFTP = 'sftp';

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'settings' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DataExchangeProfile::class, 'profile_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(DataExchangeDeliveryAttempt::class, 'delivery_target_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
