<?php

namespace App\Modules\Marketing\Models;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaign extends Model
{
    public const SCHEDULE_FREQUENCIES = [
        'daily' => 'Every day',
        'weekly' => 'Every week',
        'monthly' => 'Every month',
        'custom' => 'Custom interval',
    ];

    public const SEQUENCE_INTERVAL_UNITS = [
        'minutes' => 'Minutes',
        'hours' => 'Hours',
        'days' => 'Days',
        'weeks' => 'Weeks',
        'months' => 'Months',
    ];

    public const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    public const NEW_RECIPIENT_POLICIES = [
        'start_at_first_email' => 'Start at first email',
        'join_current_step' => 'Join current schedule',
    ];

    protected $fillable = [
        'marketing_list_id',
        'email_account_id',
        'name',
        'description',
        'status',
        'starts_at',
        'batch_size',
        'send_interval_minutes',
        'sequence_interval_value',
        'sequence_interval_unit',
        'new_recipient_policy',
        'track_opens',
        'track_clicks',
        'approved_by',
        'approved_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'approved_at' => 'datetime',
        'sequence_interval_value' => 'integer',
        'track_opens' => 'boolean',
        'track_clicks' => 'boolean',
    ];

    public function list(): BelongsTo
    {
        return $this->belongsTo(MarketingList::class, 'marketing_list_id');
    }

    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(MarketingCampaignEmail::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MarketingCampaignRecipient::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MarketingCampaignEvent::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function sequenceIntervalLabel(): string
    {
        $value = max(1, (int) ($this->sequence_interval_value ?: 1));
        $unit = $this->sequence_interval_unit ?: 'days';
        $label = self::SEQUENCE_INTERVAL_UNITS[$unit] ?? self::SEQUENCE_INTERVAL_UNITS['days'];
        $unitLabel = strtolower($label);

        if ($value === 1) {
            $unitLabel = rtrim($unitLabel, 's');
        }

        return 'Every '.$value.' '.$unitLabel;
    }

    public function scheduleFrequency(): string
    {
        $value = max(1, (int) ($this->sequence_interval_value ?: 1));
        $unit = $this->sequence_interval_unit ?: 'days';

        return match (true) {
            $value === 1 && $unit === 'days' => 'daily',
            $value === 1 && $unit === 'weeks' => 'weekly',
            $value === 1 && $unit === 'months' => 'monthly',
            default => 'custom',
        };
    }

    public function scheduleTime(): string
    {
        return $this->starts_at?->format('H:i') ?? '12:00';
    }

    public function scheduleWeekday(): int
    {
        return (int) ($this->starts_at?->format('N') ?: 5);
    }

    public function scheduleMonthDay(): int
    {
        return (int) ($this->starts_at?->format('j') ?: 1);
    }

    public function sendRhythmLabel(): string
    {
        $time = $this->scheduleTime();

        return match ($this->scheduleFrequency()) {
            'daily' => 'Every day at '.$time,
            'weekly' => 'Every '.self::WEEKDAYS[$this->scheduleWeekday()].' at '.$time,
            'monthly' => 'Every month on day '.$this->scheduleMonthDay().' at '.$time,
            default => $this->sequenceIntervalLabel().' from '.($this->starts_at?->format('Y-m-d H:i') ?? 'approval time'),
        };
    }

    public function newRecipientPolicyLabel(): string
    {
        return self::NEW_RECIPIENT_POLICIES[$this->new_recipient_policy ?: 'start_at_first_email']
            ?? self::NEW_RECIPIENT_POLICIES['start_at_first_email'];
    }
}
