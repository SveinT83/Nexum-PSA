<?php

namespace App\Modules\Notification\Models;

use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Durable, payload-free cursor for one inbound Email recipient fanout. */
class NotificationInboundEmailFanout extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const ERROR_CANDIDATE_PAGE_FAILED = 'inbound_notification_candidate_page_failed';

    public const ERROR_ATTEMPTS_EXHAUSTED = 'inbound_notification_fanout_attempts_exhausted';

    public const ERROR_SOURCE_MISSING = 'inbound_notification_fanout_source_missing';

    public const ERROR_ITEM_SCOPE_STALE = 'inbound_notification_fanout_item_scope_stale';

    protected $table = 'notification_inbound_email_fanouts';

    protected $fillable = [
        'email_message_id',
        'source_email_message_id',
        'email_account_id',
        'ticket_id',
        'ticket_queue_id',
        'ticket_owner_user_id',
        'ticket_message_id',
        'email_provider_reconciliation_item_id',
        'automation_claim_token',
        'notification_setting_through_id',
        'notification_setting_cursor_id',
        'owner_candidate_processed',
        'owner_priority_reserved',
        'status',
        'claim_token',
        'page_setting_through_id',
        'page_setting_row_count',
        'page_owner_pending',
        'page_owner_candidate_included',
        'page_attempt_count',
        'page_count',
        'last_attempt_at',
        'completed_at',
        'error_code',
    ];

    protected $casts = [
        'email_message_id' => 'integer',
        'source_email_message_id' => 'integer',
        'email_account_id' => 'integer',
        'ticket_id' => 'integer',
        'ticket_queue_id' => 'integer',
        'ticket_owner_user_id' => 'integer',
        'ticket_message_id' => 'integer',
        'email_provider_reconciliation_item_id' => 'integer',
        'notification_setting_through_id' => 'integer',
        'notification_setting_cursor_id' => 'integer',
        'owner_candidate_processed' => 'boolean',
        'owner_priority_reserved' => 'boolean',
        'page_setting_through_id' => 'integer',
        'page_setting_row_count' => 'integer',
        'page_owner_pending' => 'boolean',
        'page_owner_candidate_included' => 'boolean',
        'page_attempt_count' => 'integer',
        'page_count' => 'integer',
        'last_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }

    public function reconciliationItem(): BelongsTo
    {
        return $this->belongsTo(
            EmailProviderReconciliationItem::class,
            'email_provider_reconciliation_item_id',
        );
    }

    public function terminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }
}
