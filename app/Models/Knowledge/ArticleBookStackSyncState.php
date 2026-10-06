<?php

namespace App\Models\Knowledge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleBookStackSyncState extends Model
{
    public const STATUS_BASELINE_UNKNOWN = 'baseline_unknown';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_PENDING_INBOUND = 'pending_inbound';

    public const STATUS_PENDING_OUTBOUND = 'pending_outbound';

    public const STATUS_RESOLVING_OUTBOUND = 'resolving_outbound';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_REMOTE_DELETED = 'remote_deleted';

    public const STATUS_REMOTE_MISSING_IDENTIFIER = 'remote_missing_identifier';

    public const STATUS_ERROR = 'error';

    protected $table = 'knowledge_book_stack_sync_states';

    protected $fillable = [
        'article_id',
        'last_synced_revision_id',
        'pending_revision_id',
        'candidate_revision_id',
        'external_type',
        'external_id',
        'external_url',
        'status',
        'last_synced_local_hash',
        'last_synced_remote_hash',
        'outbound_operation_key',
        'last_direction',
        'origin',
        'last_synced_at',
        'remote_updated_at',
        'conflict_reason',
        'remote_snapshot',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'remote_updated_at' => 'datetime',
        'remote_snapshot' => 'array',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function lastSyncedRevision(): BelongsTo
    {
        return $this->belongsTo(ArticleRevision::class, 'last_synced_revision_id');
    }

    public function pendingRevision(): BelongsTo
    {
        return $this->belongsTo(ArticleRevision::class, 'pending_revision_id');
    }

    public function candidateRevision(): BelongsTo
    {
        return $this->belongsTo(ArticleRevision::class, 'candidate_revision_id');
    }
}
