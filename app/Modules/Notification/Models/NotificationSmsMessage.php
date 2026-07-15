<?php

namespace App\Modules\Notification\Models;

use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactPhone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationSmsMessage extends Model
{
    public const STATUS_DRY_RUN = 'dry_run';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'notification_channel_id',
        'notification_sms_template_id',
        'contact_id',
        'contact_phone_id',
        'actor_id',
        'provider',
        'status',
        'direction',
        'sender_name',
        'recipient_phone',
        'normalized_recipient_phone',
        'body',
        'source_type',
        'source_id',
        'provider_message_id',
        'failure_reason',
        'provider_payload',
        'metadata',
        'sent_at',
    ];

    protected $casts = [
        'provider_payload' => 'array',
        'metadata' => 'array',
        'sent_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationSmsTemplate::class, 'notification_sms_template_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function phone(): BelongsTo
    {
        return $this->belongsTo(ContactPhone::class, 'contact_phone_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
