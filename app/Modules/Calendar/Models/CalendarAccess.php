<?php

namespace App\Modules\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarAccess extends Model
{
    protected $table = 'calendar_access';

    protected $fillable = [
        'calendar_id',
        'subject_type',
        'subject_id',
        'access_level',
        'can_view_private_details',
        'can_share',
        'can_manage_access',
    ];

    protected $casts = [
        'can_view_private_details' => 'boolean',
        'can_share' => 'boolean',
        'can_manage_access' => 'boolean',
    ];

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }
}
