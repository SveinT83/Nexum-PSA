<?php

namespace App\Models\Knowledge;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Independent documentation work linked to, but not lifecycle-owned by, a Ticket.
 */
class DocumentationRequest extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DRAFT_READY = 'draft_ready';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_PUBLICATION_FAILED = 'publication_failed';

    public const STATUS_COMPLETED = 'completed';

    protected $table = 'knowledge_documentation_requests';

    protected $fillable = [
        'ticket_id',
        'request_event_id',
        'article_id',
        'revision_id',
        'requested_by',
        'status',
        'reason',
        'completed_at',
        'publication_read_back_at',
        'failure_code',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'publication_read_back_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function requestEvent(): BelongsTo
    {
        return $this->belongsTo(TicketEvent::class, 'request_event_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ArticleRevision::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_COMPLETED;
    }
}
