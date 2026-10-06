<?php

namespace App\Models\Knowledge;

use App\Models\Core\User;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Immutable Knowledge content snapshot with mutable, audited lifecycle metadata.
 */
class ArticleRevision extends Model
{
    public const STATE_DRAFT = 'draft';

    public const STATE_READY_FOR_REVIEW = 'ready_for_review';

    public const STATE_APPROVED = 'approved';

    public const STATE_REJECTED = 'rejected';

    public const STATE_PUBLISHING = 'publishing';

    public const STATE_PUBLISHED = 'published';

    public const STATE_PUBLICATION_FAILED = 'publication_failed';

    public const STATE_SUPERSEDED = 'superseded';

    public const STATE_CONFLICT = 'conflict';

    /**
     * Content and provenance fields may never change after insert. Workflow
     * transitions update only state, actor, decision, and read-back columns.
     *
     * @var list<string>
     */
    private const IMMUTABLE_FIELDS = [
        'article_id',
        'revision_number',
        'base_revision_id',
        'content_hash',
        'snapshot_hash',
        'origin',
        'source_system',
        'title',
        'body_markdown',
        'body_html',
        'visibility',
        'article_status',
        'client_scope_id',
        'owner_id',
        'category_id',
        'knowledge_shelf_id',
        'knowledge_book_id',
        'knowledge_chapter_id',
        'priority',
        'next_review_at',
        'audience_snapshot',
        'source_type',
        'source_id',
        'source_version',
        'created_by',
        'human_author_id',
        'ai_agent_id',
        'system_actor_id',
        'ticket_id',
        'documentation_request_id',
        'supersedes_revision_id',
    ];

    protected $table = 'knowledge_article_revisions';

    protected $fillable = [
        'article_id',
        'revision_number',
        'base_revision_id',
        'content_hash',
        'snapshot_hash',
        'state',
        'origin',
        'source_system',
        'title',
        'body_markdown',
        'body_html',
        'visibility',
        'article_status',
        'client_scope_id',
        'owner_id',
        'category_id',
        'knowledge_shelf_id',
        'knowledge_book_id',
        'knowledge_chapter_id',
        'priority',
        'next_review_at',
        'audience_snapshot',
        'source_type',
        'source_id',
        'source_version',
        'created_by',
        'human_author_id',
        'ai_agent_id',
        'system_actor_id',
        'ticket_id',
        'documentation_request_id',
        'submitted_by',
        'approved_by',
        'rejected_by',
        'published_by',
        'decision_note',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'published_at',
        'superseded_at',
        'supersedes_revision_id',
        'publication_status',
        'publication_read_back',
        'publication_read_back_at',
        'publication_error',
        'publication_attempts',
    ];

    protected $casts = [
        'revision_number' => 'integer',
        'priority' => 'integer',
        'next_review_at' => 'datetime',
        'audience_snapshot' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'published_at' => 'datetime',
        'superseded_at' => 'datetime',
        'publication_read_back' => 'array',
        'publication_read_back_at' => 'datetime',
        'publication_attempts' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $revision): void {
            foreach (self::IMMUTABLE_FIELDS as $field) {
                if ($revision->isDirty($field)) {
                    throw new LogicException("Knowledge revision field [{$field}] is immutable.");
                }
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Knowledge revisions are immutable and cannot be deleted.');
        });
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function baseRevision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_revision_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function humanAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'human_author_id');
    }

    public function aiAgent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    public function systemActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'system_actor_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function documentationRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentationRequest::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function supersedesRevision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_revision_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ArticleRevisionEvent::class)->orderBy('id');
    }
}
