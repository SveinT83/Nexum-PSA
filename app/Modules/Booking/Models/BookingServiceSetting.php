<?php

namespace App\Modules\Booking\Models;

use App\Models\Core\User;
use App\Modules\Commercial\Models\Services\Services;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BookingServiceSetting extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    public const MODE_REQUEST_ONLY = 'request_only';
    public const MODE_STAFF_CONFIRMED = 'staff_confirmed';

    protected $fillable = [
        'service_id',
        'assigned_user_id',
        'status',
        'slug',
        'public_name',
        'public_description',
        'booking_mode',
        'duration_minutes',
        'slot_step_minutes',
        'min_notice_hours',
        'horizon_days',
        'location',
        'instructions',
        'allow_new_clients',
        'spam_honeypot_field',
        'metadata',
    ];

    protected $casts = [
        'allow_new_clients' => 'boolean',
        'duration_minutes' => 'integer',
        'slot_step_minutes' => 'integer',
        'min_notice_hours' => 'integer',
        'horizon_days' => 'integer',
        'metadata' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Services::class, 'service_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(BookingRequest::class, 'booking_service_setting_id');
    }

    public function scopeBookable(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where('booking_mode', self::MODE_STAFF_CONFIRMED)
            ->whereHas('service', function (Builder $service): void {
                $service->where('orderable', true)
                    ->whereRaw('LOWER(status) IN (?, ?)', ['active', 'published']);
            });
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isBookable(): bool
    {
        $service = $this->service;
        $serviceStatus = strtolower((string) ($service?->status ?? ''));

        return $this->isActive()
            && $this->booking_mode === self::MODE_STAFF_CONFIRMED
            && $service !== null
            && (bool) $service->orderable
            && in_array($serviceStatus, ['active', 'published'], true);
    }

    public function publicTitle(): string
    {
        return $this->public_name ?: (string) ($this->service?->name ?? 'Booking');
    }

    public function durationLabel(): string
    {
        $minutes = max(1, (int) $this->duration_minutes);

        if ($minutes < 60) {
            return $minutes.' minutes';
        }

        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        return trim($hours.' hour'.($hours === 1 ? '' : 's').' '.($remaining ? $remaining.' minutes' : ''));
    }
}
