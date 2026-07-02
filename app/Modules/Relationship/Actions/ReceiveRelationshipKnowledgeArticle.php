<?php

namespace App\Modules\Relationship\Actions;

use App\Models\Knowledge\Article;
use App\Modules\Knowledge\Actions\RenderArticleBody;
use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\NexumSyncLink;
use App\Modules\Relationship\Support\RelationshipCapability;
use App\Modules\Relationship\Support\SyncStatus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReceiveRelationshipKnowledgeArticle
{
    public function __construct(
        private readonly RelationshipContentChecksum $checksum,
        private readonly RenderArticleBody $renderer,
        private readonly RecordSyncEvent $events,
    ) {}

    public function handle(NexumRelationship $relationship, array $data): array
    {
        if (! $relationship->supports(RelationshipCapability::KNOWLEDGE_SYNC)) {
            throw ValidationException::withMessages(['relationship' => 'Knowledge sync is not enabled for this relationship.']);
        }

        if (($data['visibility'] ?? 'internal') === 'internal') {
            throw ValidationException::withMessages(['article' => 'Internal knowledge articles are not accepted through relationship sync.']);
        }

        $remoteId = (string) $data['source_article_id'];
        $payloadChecksum = $this->checksum->handle($data);
        $link = NexumSyncLink::query()
            ->where('relationship_id', $relationship->id)
            ->where('domain', 'knowledge')
            ->where('remote_type', 'knowledge_article')
            ->where('remote_id', $remoteId)
            ->first();

        $article = $link?->local_id ? Article::query()->find($link->local_id) : null;

        if ($article && $link->remote_checksum && $this->checksum->handle($this->localPayload($article)) !== $link->remote_checksum) {
            $link->markConflict('Local knowledge article changed after the last relationship sync.', [
                'incoming_checksum' => $payloadChecksum,
            ]);

            return [$article, $link->refresh(), false, true];
        }

        $visibility = $relationship->client_id ? 'client-wide' : 'public';
        $attributes = [
            'title' => $data['title'],
            'slug' => Str::slug($data['title']).'-'.Str::random(5),
            'body_markdown' => $data['body_markdown'],
            'body_html' => $this->renderer->handle($data['body_markdown']),
            'visibility' => $visibility,
            'status' => $data['status'] ?? 'published',
            'client_scope_id' => $visibility === 'client-wide' ? $relationship->client_id : null,
            'source_system' => 'nexum_relationship',
            'source_type' => 'knowledge_article',
            'source_id' => $remoteId,
            'source_checksum' => $payloadChecksum,
            'source_synced_at' => now(),
            'source_updated_at' => $data['updated_at'] ?? null,
            'sync_status' => 'synced',
            'source_payload' => $data,
        ];

        if ($article) {
            unset($attributes['slug']);
            $article->forceFill($attributes)->save();
            $created = false;
        } else {
            $article = Article::query()->create($attributes);
            $created = true;
        }

        $link = $link ?: NexumSyncLink::query()->create([
            'relationship_id' => $relationship->id,
            'domain' => 'knowledge',
            'local_type' => Article::class,
            'local_id' => $article->id,
            'remote_type' => 'knowledge_article',
            'remote_id' => $remoteId,
            'direction' => 'inbound',
            'sync_status' => SyncStatus::PENDING,
        ]);

        $link->markSynced([
            'local_id' => $article->id,
            'remote_checksum' => $payloadChecksum,
            'remote_updated_at' => isset($data['updated_at']) ? $data['updated_at'] : null,
        ]);

        $this->events->handle($relationship, [
            'sync_link_id' => $link->id,
            'direction' => 'inbound',
            'capability' => RelationshipCapability::KNOWLEDGE_SYNC,
            'local_type' => Article::class,
            'local_id' => $article->id,
            'remote_type' => 'knowledge_article',
            'remote_id' => $remoteId,
            'event_type' => 'knowledge_article_received',
            'payload_checksum' => $payloadChecksum,
            'outcome' => 'synced',
        ]);

        return [$article->refresh(), $link->refresh(), $created, false];
    }

    private function localPayload(Article $article): array
    {
        return [
            'title' => $article->title,
            'body_markdown' => $article->body_markdown,
            'visibility' => $article->visibility,
            'status' => $article->status,
        ];
    }
}
