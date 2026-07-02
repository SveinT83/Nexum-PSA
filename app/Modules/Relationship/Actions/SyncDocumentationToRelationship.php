<?php

namespace App\Modules\Relationship\Actions;

use App\Modules\Documentation\Models\Documentation;
use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\NexumSyncLink;
use App\Modules\Relationship\Support\RelationshipCapability;
use App\Modules\Relationship\Support\SyncStatus;
use Illuminate\Validation\ValidationException;

class SyncDocumentationToRelationship
{
    public function __construct(
        private readonly NexumRelationshipHttpClient $client,
        private readonly RelationshipContentChecksum $checksum,
    ) {}

    public function handle(Documentation $documentation, NexumRelationship $relationship): NexumSyncLink
    {
        if (! $relationship->isActive() || ! $relationship->supports(RelationshipCapability::DOCUMENTATION_SYNC)) {
            throw ValidationException::withMessages(['relationship' => 'Documentation sync is not enabled for this relationship.']);
        }

        if ($documentation->scope_type === 'internal') {
            throw ValidationException::withMessages(['documentation' => 'Internal documentation cannot be synced through a relationship.']);
        }

        if ($relationship->isProviderForClient() && (int) $documentation->client_id !== (int) $relationship->client_id) {
            throw ValidationException::withMessages(['documentation' => 'This documentation record does not belong to the relationship client.']);
        }

        $payload = $this->payload($documentation);
        $checksum = $this->checksum->handle($payload);
        $link = NexumSyncLink::query()->firstOrCreate(
            [
                'relationship_id' => $relationship->id,
                'domain' => 'documentation',
                'local_type' => Documentation::class,
                'local_id' => $documentation->id,
            ],
            [
                'remote_type' => 'documentation',
                'direction' => 'outbound',
                'sync_status' => SyncStatus::PENDING,
            ]
        );

        $result = $this->client->post($relationship, 'documentation', $payload, RelationshipCapability::DOCUMENTATION_SYNC, $link, 'documentation_synced');

        if ($result['ok']) {
            $remote = $result['data']['data'] ?? $result['data'];
            $link->markSynced([
                'remote_id' => (string) ($remote['remote_id'] ?? $remote['id'] ?? $link->remote_id),
                'remote_checksum' => $checksum,
                'remote_updated_at' => $documentation->updated_at,
            ]);
        }

        return $link->refresh();
    }

    private function payload(Documentation $documentation): array
    {
        $documentation->loadMissing(['category', 'template', 'client', 'site']);

        return [
            'source_documentation_id' => (string) $documentation->id,
            'title' => $documentation->title,
            'scope_type' => $documentation->scope_type,
            'category' => [
                'name' => $documentation->category?->name ?: 'Relationship',
                'slug' => $documentation->category?->slug ?: 'relationship',
            ],
            'template' => [
                'name' => $documentation->template?->name ?: 'Relationship Document',
                'fields' => $documentation->template_snapshot_json ?: [],
            ],
            'data' => $documentation->data_json ?: [],
            'content' => ($documentation->data_json ?? [])['content'] ?? null,
            'client' => [
                'id' => $documentation->client_id,
                'name' => $documentation->client?->name,
            ],
            'site' => [
                'id' => $documentation->site_id,
                'name' => $documentation->site?->name,
            ],
            'updated_at' => $documentation->updated_at?->toISOString(),
        ];
    }
}
