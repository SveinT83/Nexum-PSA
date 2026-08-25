<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailTicketConversationLinkMigrationRun extends Model
{
    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'email_ticket_conversation_link_migration_runs';

    protected $guarded = [];

    protected $casts = [
        'item_cap' => 'integer',
        'candidate_count' => 'integer',
        'ready_count' => 'integer',
        'already_mapped_count' => 'integer',
        'conflict_count' => 'integer',
        'applied_count' => 'integer',
        'failed_count' => 'integer',
        'previewed_at' => 'datetime',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmailTicketConversationLinkMigrationItem::class, 'run_id');
    }
}
