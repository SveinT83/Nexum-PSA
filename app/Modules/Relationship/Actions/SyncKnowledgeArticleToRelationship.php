<?php

namespace App\Modules\Relationship\Actions;

use App\Models\Knowledge\Article;
use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\NexumSyncLink;
use App\Modules\Relationship\Support\RelationshipCapability;
use App\Modules\Relationship\Support\SyncStatus;
use Illuminate\Validation\ValidationException;

class SyncKnowledgeArticleToRelationship
{
    public function __construct(
        private readonly NexumRelationshipHttpClient $client,
        private readonly RelationshipContentChecksum $checksum,
    ) {}

    public function handle(Article $article, NexumRelationship $relationship): NexumSyncLink
    {
        if (! $relationship->isActive() || ! $relationship->supports(RelationshipCapability::KNOWLEDGE_SYNC)) {
            throw ValidationException::withMessages(['relationship' => 'Knowledge sync is not enabled for this relationship.']);
        }

        if ($article->visibility === 'internal') {
            throw ValidationException::withMessages(['article' => 'Internal knowledge articles cannot be synced through a relationship.']);
        }

        if ($article->visibility === 'client-wide' && $relationship->isProviderForClient() && (int) $article->client_scope_id !== (int) $relationship->client_id) {
            throw ValidationException::withMessages(['article' => 'This article does not belong to the relationship client.']);
        }

        $payload = [
            'source_article_id' => (string) $article->id,
            'title' => $article->title,
            'body_markdown' => $article->body_markdown,
            'visibility' => $article->visibility,
            'status' => $article->status,
            'client_scope_id' => $article->client_scope_id,
            'updated_at' => $article->updated_at?->toISOString(),
        ];
        $checksum = $this->checksum->handle($payload);
        $link = NexumSyncLink::query()->firstOrCreate(
            [
                'relationship_id' => $relationship->id,
                'domain' => 'knowledge',
                'local_type' => Article::class,
                'local_id' => $article->id,
            ],
            [
                'remote_type' => 'knowledge_article',
                'direction' => 'outbound',
                'sync_status' => SyncStatus::PENDING,
            ]
        );

        $result = $this->client->post($relationship, 'knowledge/articles', $payload, RelationshipCapability::KNOWLEDGE_SYNC, $link, 'knowledge_article_synced');

        if ($result['ok']) {
            $remote = $result['data']['data'] ?? $result['data'];
            $link->markSynced([
                'remote_id' => (string) ($remote['remote_id'] ?? $remote['id'] ?? $link->remote_id),
                'remote_checksum' => $checksum,
                'remote_updated_at' => $article->updated_at,
            ]);
        }

        return $link->refresh();
    }
}
