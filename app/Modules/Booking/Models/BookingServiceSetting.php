<?php

namespace App\Modules\Booking\Models;

use App\Models\Core\User;
use App\Modules\Commercial\Models\Services\Services;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    public const ROUTING_FIXED = 'fixed';

    public const ROUTING_AUTOMATIC = 'automatic';

    public const ROUTING_CUSTOMER_CHOICE = 'customer_choice';

    public const HOURS_COMPANY = 'company';

    public const HOURS_TECHNICIAN = 'technician';

    protected $fillable = [
        'service_id',
        'assigned_user_id',
        'technician_routing_mode',
        'working_hours_source',
        'status',
        'slug',
        'public_name',
        'public_description',
        'booking_mode',
        'duration_minutes',
        'slot_step_minutes',
        'min_notice_hours',
        'horizon_days',
        'opening_window_start',
        'opening_window_end',
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

    public function eligibleUsers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'booking_service_setting_user'
        )->withTimestamps();
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
            })
            ->where(function (Builder $routing): void {
                $routing
                    ->where(function (Builder $fixed): void {
                        $fixed->where('technician_routing_mode', self::ROUTING_FIXED)
                            ->whereHas('assignedUser', function (Builder $user): void {
                                $user->where('status', User::STATUS_ACTIVE);
                            });
                    })
                    ->orWhere(function (Builder $pool): void {
                        $pool->whereIn('technician_routing_mode', [
                            self::ROUTING_AUTOMATIC,
                            self::ROUTING_CUSTOMER_CHOICE,
                        ])->whereHas('eligibleUsers', function (Builder $user): void {
                            $user->where('status', User::STATUS_ACTIVE);
                        });
                    });
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
            && in_array($serviceStatus, ['active', 'published'], true)
            && $this->hasBookableTechnicianConfiguration();
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

    public function routingModeLabel(): string
    {
        return match ($this->technician_routing_mode) {
            self::ROUTING_AUTOMATIC => 'Automatic assignment',
            self::ROUTING_CUSTOMER_CHOICE => 'Customer chooses technician',
            default => 'Fixed technician',
        };
    }

    public function workingHoursSourceLabel(): string
    {
        return $this->working_hours_source === self::HOURS_TECHNICIAN
            ? 'Technician working hours'
            : 'Company working hours';
    }

    public function openingWindowLabel(): string
    {
        if (! $this->opening_window_start || ! $this->opening_window_end) {
            return 'Full working hours';
        }

        return substr((string) $this->opening_window_start, 0, 5)
            .' - '
            .substr((string) $this->opening_window_end, 0, 5);
    }

    public function usesFixedTechnician(): bool
    {
        return ($this->technician_routing_mode ?: self::ROUTING_FIXED) === self::ROUTING_FIXED;
    }

    public function usesAutomaticAssignment(): bool
    {
        return $this->technician_routing_mode === self::ROUTING_AUTOMATIC;
    }

    public function allowsCustomerTechnicianChoice(): bool
    {
        return $this->technician_routing_mode === self::ROUTING_CUSTOMER_CHOICE;
    }

    private function hasBookableTechnicianConfiguration(): bool
    {
        if ($this->usesFixedTechnician()) {
            return $this->assignedUser !== null && $this->assignedUser->isActive();
        }

        $eligibleUsers = $this->relationLoaded('eligibleUsers')
            ? $this->eligibleUsers
            : $this->eligibleUsers()->get();

        return $eligibleUsers->contains(fn (User $user): bool => $user->isActive());
    }
}
