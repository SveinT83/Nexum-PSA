<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EmailSmartInboxSuggestionEvent extends Model
{
    public const TYPE_GENERATED = 'generated';

    public const TYPE_CORRECTED = 'corrected';

    public const TYPE_DISMISSED = 'dismissed';

    public const TYPE_STALE = 'stale';

    public const TYPE_RECOVERED = 'recovered';

    public const TYPE_REVOKED = 'revoked';

    public const TYPE_APPLIED = 'applied';

    protected $table = 'email_smart_inbox_suggestion_events';

    public $timestamps = false;

    protected $fillable = [
        'email_smart_inbox_suggestion_id',
        'actor_id',
        'event_type',
        'from_status',
        'to_status',
        'reason_code',
        'before_json',
        'after_json',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'email_smart_inbox_suggestion_id' => 'integer',
        'actor_id' => 'integer',
        'before_json' => 'array',
        'after_json' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Smart Inbox suggestion events are immutable audit evidence.');
        });

        static::deleting(function (): void {
            throw new LogicException('Smart Inbox suggestion events are immutable audit evidence.');
        });
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(EmailSmartInboxSuggestion::class, 'email_smart_inbox_suggestion_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
