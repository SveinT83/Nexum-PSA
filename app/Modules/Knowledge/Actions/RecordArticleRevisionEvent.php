<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\ArticleRevision;
use App\Models\Knowledge\ArticleRevisionEvent;
use Illuminate\Support\Arr;

/**
 * Appends a sanitized workflow event without copying article content.
 */
class RecordArticleRevisionEvent
{
    private const METADATA_KEYS = [
        'reason_code',
        'read_back',
        'provider',
        'provider_id',
        'request_id',
        'ticket_id',
        'source_revision_id',
        'operation_key',
    ];

    /** @param array<string, mixed> $metadata */
    public function handle(
        ArticleRevision $revision,
        string $eventType,
        ?int $actorId,
        ?string $fromState,
        ?string $toState,
        ?string $note = null,
        array $metadata = [],
    ): ArticleRevisionEvent {
        return ArticleRevisionEvent::create([
            'article_revision_id' => $revision->id,
            'actor_id' => $actorId,
            'event_type' => $eventType,
            'from_state' => $fromState,
            'to_state' => $toState,
            'note' => filled($note) ? mb_substr($note, 0, 2000) : null,
            'metadata' => Arr::only($metadata, self::METADATA_KEYS),
        ]);
    }
}
