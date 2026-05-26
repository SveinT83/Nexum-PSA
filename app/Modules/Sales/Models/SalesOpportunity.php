<?php

namespace App\Modules\Sales\Models;

use App\Models\Clients\Client;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Calendar\Models\CalendarEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesOpportunity extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'opportunity_key',
        'client_id',
        'primary_contact_id',
        'owner_id',
        'title',
        'type',
        'status',
        'summary',
        'needs',
        'employee_count_estimate',
        'user_count_estimate',
        'workstation_count_estimate',
        'server_count_estimate',
        'site_count_estimate',
        'estimated_value_ex_vat',
        'probability_percent',
        'weighted_value_ex_vat',
        'expected_close_date',
        'next_follow_up_at',
        'next_follow_up_type',
        'next_follow_up_note',
        'is_unread',
        'follow_up_calendar_event_id',
        'current_quote_version_id',
        'won_quote_version_id',
        'won_at',
        'lost_at',
        'lost_reason',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'employee_count_estimate' => 'integer',
        'user_count_estimate' => 'integer',
        'workstation_count_estimate' => 'integer',
        'server_count_estimate' => 'integer',
        'site_count_estimate' => 'integer',
        'estimated_value_ex_vat' => 'decimal:2',
        'probability_percent' => 'integer',
        'weighted_value_ex_vat' => 'decimal:2',
        'expected_close_date' => 'date',
        'next_follow_up_at' => 'datetime',
        'is_unread' => 'boolean',
        'won_at' => 'datetime',
        'lost_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'opportunity_key';
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function primaryContact(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class, 'primary_contact_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function followUpCalendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'follow_up_calendar_event_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(SalesActivity::class, 'opportunity_id')->latest();
    }

    public function stakeholders(): HasMany
    {
        return $this->hasMany(SalesOpportunityStakeholder::class, 'opportunity_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(SalesQuote::class, 'opportunity_id');
    }

    public function currentQuoteVersion(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteVersion::class, 'current_quote_version_id');
    }

    public function wonQuoteVersion(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteVersion::class, 'won_quote_version_id');
    }
}
