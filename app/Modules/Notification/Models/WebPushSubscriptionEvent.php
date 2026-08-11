<?php

namespace App\Modules\Notification\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebPushSubscriptionEvent extends Model
{
    protected $fillable = [
        'target_user_id',
        'actor_id',
        'subscription_public_id',
        'action',
        'device_label',
        'browser_family',
        'platform_family',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
