<?php

namespace App\Modules\UserManagement\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Canonical profile record for platform-wide user and technician data.
 *
 * Ticket assignment may read these values, but Ticket must not own general
 * profile state such as work phone, availability, timezone, or work hours.
 */
class UserProfile extends Model
{
    protected $fillable = [
        'user_id',
        'avatar_path',
        'work_phone',
        'private_phone',
        'timezone',
        'working_hours',
        'availability_notes',
        'profile_notes',
        'migrated_from_ticket_technician_profile_id',
        'migrated_at',
    ];

    protected $casts = [
        'working_hours' => 'array',
        'migrated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function avatarUrl(): ?string
    {
        return filled($this->avatar_path)
            ? Storage::disk('public')->url($this->avatar_path)
            : null;
    }
}
