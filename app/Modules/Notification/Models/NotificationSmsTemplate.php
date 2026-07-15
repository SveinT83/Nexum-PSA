<?php

namespace App\Modules\Notification\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationSmsTemplate extends Model
{
    protected $fillable = [
        'key',
        'name',
        'body',
        'variables',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];
}
