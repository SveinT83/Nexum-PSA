<?php

namespace App\Models\Knowledge;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sanitized append-only audit event for a Knowledge revision transition.
 */
class ArticleRevisionEvent extends Model
{
    protected $table = 'knowledge_article_revision_events';

    protected $fillable = [
        'article_revision_id',
        'actor_id',
        'event_type',
        'from_state',
        'to_state',
        'note',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('Knowledge revision events are append-only.');
        });

        static::deleting(function (): void {
            throw new \LogicException('Knowledge revision events are append-only.');
        });
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ArticleRevision::class, 'article_revision_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
