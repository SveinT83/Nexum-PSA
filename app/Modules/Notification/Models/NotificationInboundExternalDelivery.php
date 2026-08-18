<?php

namespace App\Modules\Notification\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationInboundExternalDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SUPPRESSED = 'suppressed';

    public const STATUS_UNRESOLVED = 'unresolved';

    protected $fillable = [
        'notification_id',
        'user_id',
        'inbound_notification_fanout_id',
        'canonical_payload_hash',
        'requested_mail',
        'requested_web_push',
        'requested_nextcloud_talk',
        'mail_scope',
        'mail_account_id',
        'mail_provider_binding_version',
        'mail_snapshot_failure_code',
        'status',
        'claim_token',
        'attempt_count',
        'last_attempt_at',
        'completed_at',
        'error_code',
    ];

    protected $casts = [
        'notification_id' => 'string',
        'user_id' => 'integer',
        'inbound_notification_fanout_id' => 'integer',
        'canonical_payload_hash' => 'string',
        'requested_mail' => 'boolean',
        'requested_web_push' => 'boolean',
        'requested_nextcloud_talk' => 'boolean',
        'mail_account_id' => 'integer',
        'mail_provider_binding_version' => 'integer',
        'attempt_count' => 'integer',
        'last_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function terminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_SUPPRESSED,
            self::STATUS_UNRESOLVED,
        ], true);
    }
}
