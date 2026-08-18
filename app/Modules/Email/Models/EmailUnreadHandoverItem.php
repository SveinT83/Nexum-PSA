<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailUnreadHandoverItem extends Model
{
    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_ALREADY_UNREAD = 'already_unread';

    public const STATUS_STALE = 'stale';

    protected $table = 'email_unread_handover_items';

    protected $fillable = [
        'email_unread_handover_run_id',
        'snapshot_order',
        'email_message_id',
        'email_mailbox_placement_id',
        'email_folder_id',
        'access_epoch',
        'status',
        'applied_at',
        'error_code',
    ];

    protected $casts = [
        'email_unread_handover_run_id' => 'integer',
        'snapshot_order' => 'integer',
        'email_message_id' => 'integer',
        'email_mailbox_placement_id' => 'integer',
        'email_folder_id' => 'integer',
        'access_epoch' => 'integer',
        'applied_at' => 'immutable_datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(EmailUnreadHandoverRun::class, 'email_unread_handover_run_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id')->withTrashed();
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(EmailMailboxPlacement::class, 'email_mailbox_placement_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(EmailFolder::class, 'email_folder_id');
    }
}
