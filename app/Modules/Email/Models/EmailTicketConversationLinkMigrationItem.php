<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTicketConversationLinkMigrationItem extends Model
{
    public const STATUS_READY = 'ready';

    public const STATUS_ALREADY_MAPPED = 'already_mapped';

    public const STATUS_MISSING_CONVERSATION = 'missing_conversation';

    public const STATUS_MISSING_TICKET = 'missing_ticket';

    public const STATUS_PRIMARY_CONFLICT = 'primary_conflict';

    public const STATUS_AUDIENCE_CONFLICT = 'audience_conflict';

    public const STATUS_ACCOUNT_CONFLICT = 'account_conflict';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_STALE = 'stale';

    public const STATUS_FAILED = 'failed';

    protected $table = 'email_ticket_conversation_link_migration_items';

    protected $guarded = [];

    protected $casts = [
        'email_message_id' => 'integer',
        'ticket_id' => 'integer',
        'account_id' => 'integer',
        'email_mailbox_placement_id' => 'integer',
        'email_conversation_id' => 'integer',
        'ticket_message_id' => 'integer',
        'applied_link_id' => 'integer',
        'evidence' => 'array',
        'applied_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(EmailTicketConversationLinkMigrationRun::class, 'run_id');
    }

    public function appliedLink(): BelongsTo
    {
        return $this->belongsTo(EmailTicketConversationLink::class, 'applied_link_id');
    }
}
