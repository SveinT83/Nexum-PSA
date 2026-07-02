<?php

namespace App\Modules\Relationship\Actions;

use App\Modules\Documentation\Models\Documentation;
use App\Modules\Documentation\Models\DocumentationTemplate;
use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\NexumSyncLink;
use App\Modules\Relationship\Support\RelationshipCapability;
use App\Modules\Relationship\Support\SyncStatus;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReceiveRelationshipDocumentation
{
    public function __construct(
        private readonly RelationshipContentChecksum $checksum,
        private readonly RecordSyncEvent $events,
        private readonly ResolveWorkContext $workContexts,
    ) {}

    public function handle(NexumRelationship $relationship, array $data): array
    {
        if (! $relationship->supports(RelationshipCapability::DOCUMENTATION_SYNC)) {
            throw ValidationException::withMessages(['relationship' => 'Documentation sync is not enabled for this relationship.']);
        }

        if (($data['scope_type'] ?? 'internal') === 'internal') {
            throw ValidationException::withMessages(['documentation' => 'Internal documentation is not accepted through relationship sync.']);
        }

        $remoteId = (string) $data['source_documentation_id'];
        $payloadChecksum = $this->checksum->handle($data);
        $link = NexumSyncLink::query()
            ->where('relationship_id', $relationship->id)
            ->where('domain', 'documentation')
            ->where('remote_type', 'documentation')
            ->where('remote_id', $remoteId)
            ->first();

        $documentation = $link?->local_id ? Documentation::query()->find($link->local_id) : null;

        if ($documentation && $link->remote_checksum && $this->checksum->handle($this->localPayload($documentation)) !== $link->remote_checksum) {
            $link->markConflict('Local documentation changed after the last relationship sync.', [
                'incoming_checksum' => $payloadChecksum,
            ]);

            return [$documentation, $link->refresh(), false, true];
        }

        $category = $this->category($data);
        $template = $this->template($category, $data);
        $clientId = $relationship->client_id;
        $scopeType = $clientId ? 'client' : 'internal';

        if (! $clientId) {
            throw ValidationException::withMessages(['relationship' => 'Inbound documentation requires a linked client.']);
        }

        $attributes = [
            'template_id' => $template->id,
            'category_id' => $category->id,
            'client_id' => $clientId,
            'work_context_id' => $this->workContexts->fromClientId($clientId)->id,
            'site_id' => null,
            'title' => $data['title'],
            'scope_type' => $scopeType,
            'template_snapshot_json' => $data['template']['fields'] ?? $template->fields ?? [],
            'data_json' => array_merge($data['data'] ?? [], [
                'content' => $data['content'] ?? ($data['data']['content'] ?? null),
            ]),
        ];

        if ($documentation) {
            $documentation->forceFill($attributes)->save();
            $created = false;
        } else {
            $documentation = Documentation::query()->create($attributes);
            $created = true;
        }

        $link = $link ?: NexumSyncLink::query()->create([
            'relationship_id' => $relationship->id,
            'domain' => 'documentation',
            'local_type' => Documentation::class,
            'local_id' => $documentation->id,
            'remote_type' => 'documentation',
            'remote_id' => $remoteId,
            'direction' => 'inbound',
            'sync_status' => SyncStatus::PENDING,
        ]);

        $link->markSynced([
            'local_id' => $documentation->id,
            'remote_checksum' => $payloadChecksum,
            'remote_updated_at' => isset($data['updated_at']) ? $data['updated_at'] : null,
        ]);

        $this->events->handle($relationship, [
            'sync_link_id' => $link->id,
            'direction' => 'inbound',
            'capability' => RelationshipCapability::DOCUMENTATION_SYNC,
            'local_type' => Documentation::class,
            'local_id' => $documentation->id,
            'remote_type' => 'documentation',
            'remote_id' => $remoteId,
            'event_type' => 'documentation_received',
            'payload_checksum' => $payloadChecksum,
            'outcome' => 'synced',
        ]);

        return [$documentation->refresh(), $link->refresh(), $created, false];
    }

    private function category(array $data): Category
    {
        $name = $data['category']['name'] ?? 'Relationship';
        $slug = $data['category']['slug'] ?? Str::slug($name);

        return Category::query()->firstOrCreate(
            ['type' => 'documentation', 'slug' => $slug],
            ['name' => $name, 'is_active' => true]
        );
    }

    private function template(Category $category, array $data): DocumentationTemplate
    {
        $name = $data['template']['name'] ?? 'Relationship Document';

        return DocumentationTemplate::query()->firstOrCreate(
            ['category_id' => $category->id, 'name' => $name],
            [
                'fields' => $data['template']['fields'] ?? [
                    ['Name' => 'content', 'labelName' => 'Content', 'type' => 'textarea'],
                ],
                'is_active' => true,
            ]
        );
    }

    private function localPayload(Documentation $documentation): array
    {
        return [
            'title' => $documentation->title,
            'scope_type' => $documentation->scope_type,
            'data' => $documentation->data_json ?? [],
        ];
    }
}
