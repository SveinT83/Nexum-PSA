<?php

namespace App\Modules\Booking\Models;

use App\Models\Core\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Commercial\Models\Services\Services;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingRequest extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_SPAM = 'spam';

    protected $fillable = [
        'booking_key',
        'booking_service_setting_id',
        'service_id',
        'assigned_user_id',
        'calendar_event_id',
        'status',
        'booking_mode',
        'company_name',
        'contact_name',
        'contact_email',
        'contact_phone',
        'message',
        'requested_date',
        'requested_starts_at',
        'requested_ends_at',
        'timezone',
        'source_url',
        'referrer',
        'ip_address',
        'user_agent',
        'raw_payload',
        'customer_requested_notification_sent_at',
        'customer_confirmation_notification_sent_at',
        'customer_decline_notification_sent_at',
        'confirmed_at',
        'confirmed_by',
        'declined_at',
        'declined_by',
        'decline_reason',
        'metadata',
    ];

    protected $casts = [
        'requested_date' => 'date',
        'requested_starts_at' => 'datetime',
        'requested_ends_at' => 'datetime',
        'customer_requested_notification_sent_at' => 'datetime',
        'customer_confirmation_notification_sent_at' => 'datetime',
        'customer_decline_notification_sent_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'declined_at' => 'datetime',
        'raw_payload' => 'array',
        'metadata' => 'array',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(BookingServiceSetting::class, 'booking_service_setting_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Services::class, 'service_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function declinedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declined_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BookingRequestEvent::class)->latest();
    }

    public function isRequested(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    public function slotLabel(): string
    {
        if (! $this->requested_starts_at || ! $this->requested_ends_at) {
            return 'No slot selected';
        }

        $timezone = $this->timezone ?: 'Europe/Oslo';

        return $this->requested_starts_at->timezone($timezone)->format('Y-m-d H:i')
            .' - '
            .$this->requested_ends_at->timezone($timezone)->format('H:i');
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_REQUESTED => 'text-bg-primary',
            self::STATUS_CONFIRMED => 'text-bg-success',
            self::STATUS_DECLINED, self::STATUS_CANCELLED => 'text-bg-secondary',
            self::STATUS_SPAM => 'text-bg-warning',
            default => 'text-bg-light border',
        };
    }
}
