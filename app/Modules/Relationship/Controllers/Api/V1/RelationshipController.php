<?php

namespace App\Modules\Relationship\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Relationship\Actions\ExecuteRelationshipCommand;
use App\Modules\Relationship\Actions\SaveRelationshipConfiguration;
use App\Modules\Relationship\Actions\SyncDocumentationToRelationship;
use App\Modules\Relationship\Actions\SyncKnowledgeArticleToRelationship;
use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\RelationshipApiCommand;
use App\Modules\Relationship\Support\RelationshipApiAccess as Access;
use App\Modules\Relationship\Support\RelationshipApiInput as Input;
use App\Modules\Relationship\Support\RelationshipCapability;
use App\Modules\Relationship\Support\RelationshipContentAccess as Content;
use App\Modules\Relationship\Support\RelationshipDirection;
use App\Modules\Relationship\Support\RelationshipProjection as Projection;
use App\Modules\Relationship\Support\RelationshipStatus;
use App\Modules\Relationship\Support\RelationshipType;
use App\Modules\Ticket\Models\TicketQueue;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Transport adapters delegate configuration, policy and outbound execution to domain actions. */
class RelationshipController extends Controller
{
    public function index(Request $request)
    {
        $size = Input::page($request);
        $data = $request->validate(['q' => 'sometimes|string|max:255', 'status' => ['sometimes', Rule::in(RelationshipStatus::values())],
            'direction' => ['sometimes', Rule::in(RelationshipDirection::values())], 'client_id' => 'sometimes|integer|min:1', 'vendor_id' => 'sometimes|integer|min:1']);
        $query = NexumRelationship::query();
        foreach (['status', 'direction', 'client_id', 'vendor_id'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['q'])) {
            $query->where('name', 'like', '%'.$data['q'].'%');
        }
        $rows = $query->orderByDesc('id')->paginate($size);
        $rows->through(fn ($row) => Projection::relationship($row));

        return response()->json(array_merge($rows->toArray(), ['stats' => [
            'active' => NexumRelationship::where('status', 'active')->count(),
            'failing' => NexumRelationship::where('health_status', 'failing')->count()]]));
    }

    public function show(NexumRelationship $relationship)
    {
        return response()->json(['data' => Projection::relationship($relationship)])->header('ETag', Projection::version($relationship));
    }

    public function context(?NexumRelationship $relationship = null)
    {
        return response()->json(['data' => [
            'relationship' => $relationship ? Projection::relationship($relationship) : null,
            'defaults' => ['direction' => RelationshipDirection::WE_ARE_PROVIDER, 'relationship_type' => RelationshipType::CUSTOMER_PROVIDER,
                'status' => 'draft', 'capabilities' => [], 'ticket_auto_create_queue' => true, 'documentation_two_way' => true,
                'attachment_max_mb' => 10, 'attachment_allowed_content_types' => [], 'service_areas' => ['it'], 'status_mapping' => (object) []],
            'directions' => RelationshipDirection::labels(), 'statuses' => RelationshipStatus::values(), 'types' => RelationshipType::values(),
            'capabilities' => RelationshipCapability::values(), 'outbound_execution_enabled' => (bool) config('relationship-api.outbound_execution_enabled', false),
            'lookups_url' => url('/api/v1/relationships/lookups'),
        ]]);
    }

    public function lookups(Request $request)
    {
        $size = Input::page($request);
        $data = $request->validate(['type' => ['required', Rule::in(['clients', 'vendors', 'queues'])], 'q' => 'sometimes|string|max:255']);
        $query = match ($data['type']) {
            'clients' => Client::where('active', true)->select(['id', 'name', 'client_number']),
            'vendors' => Vendor::where('is_active', true)->select(['id', 'name', 'vendor_code']),
            'queues' => TicketQueue::where('is_active', true)->select(['id', 'name']),
        };
        if (isset($data['q'])) {
            $query->where('name', 'like', '%'.$data['q'].'%');
        }

        return response()->json($query->orderBy('name')->orderBy('id')->paginate($size));
    }

    public function store(Request $request, ExecuteRelationshipCommand $commands)
    {
        $this->credentialAuthority($request);
        $result = $commands->handle($request, 'create', null, function () use ($request) {
            $row = new NexumRelationship;
            $data = Input::configuration($request, $row);
            app(SaveRelationshipConfiguration::class)->handle($row, $data, $request->user());

            return ['relationship' => Projection::relationship($row->refresh())];
        });

        return response()->json(['data' => $result], $result['replayed'] ? 200 : 201)->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, NexumRelationship $relationship, ExecuteRelationshipCommand $commands)
    {
        $this->credentialAuthority($request);
        $result = $commands->handle($request, 'update', $relationship, function () use ($request, $relationship) {
            app(SaveRelationshipConfiguration::class)->handle($relationship, Input::configuration($request, $relationship), $request->user());

            return ['relationship' => Projection::relationship($relationship->refresh())];
        });

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function rotate(Request $request, NexumRelationship $relationship, ExecuteRelationshipCommand $commands)
    {
        $this->credentialAuthority($request, true);
        $data = $request->validate(['rotate_inbound_token' => 'sometimes|boolean', 'rotate_webhook_secret' => 'sometimes|boolean']);
        abort_unless(($data['rotate_inbound_token'] ?? false) || ($data['rotate_webhook_secret'] ?? false), 422, 'Select a credential to rotate.');
        $result = $commands->handle($request, 'rotate', $relationship, function () use ($request, $relationship, $data) {
            $token = app(SaveRelationshipConfiguration::class)->rotate($relationship, $data, $request->user());

            return ['relationship' => Projection::relationship($relationship->refresh()), 'inbound_token' => $token];
        });

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function health(NexumRelationship $relationship)
    {
        return response()->json(['data' => Projection::health($relationship)]);
    }

    public function links(Request $request, NexumRelationship $relationship)
    {
        $rows = $relationship->syncLinks()->orderByDesc('id')->paginate(Input::page($request));
        $rows->through(fn ($row) => Projection::link($row));

        return response()->json($rows);
    }

    public function link(NexumRelationship $relationship, int $link)
    {
        return response()->json(['data' => Projection::link($relationship->syncLinks()->findOrFail($link))]);
    }

    public function events(Request $request, NexumRelationship $relationship)
    {
        $rows = $relationship->syncEvents()->orderByDesc('occurred_at')->orderByDesc('id')->paginate(Input::page($request));
        $rows->through(fn ($row) => Projection::event($row));

        return response()->json($rows);
    }

    public function event(NexumRelationship $relationship, int $event)
    {
        return response()->json(['data' => Projection::event($relationship->syncEvents()->findOrFail($event))]);
    }

    public function eligible(Request $request, NexumRelationship $relationship)
    {
        $rows = Content::query($relationship, $request->route()->defaults['relationship_content'])->orderByDesc('updated_at')->orderByDesc('id')->paginate(Input::page($request));
        $rows->through(fn ($row) => Content::summary($row));

        return response()->json($rows);
    }

    public function preview(Request $request, NexumRelationship $relationship, int $source)
    {
        $type = $request->route()->defaults['relationship_content'];
        $record = Content::source($relationship, $type, $source);
        $capability = $type === 'documentation' ? RelationshipCapability::DOCUMENTATION_SYNC : RelationshipCapability::KNOWLEDGE_SYNC;

        return response()->json(['data' => ['relationship_id' => $relationship->id, 'relationship_version' => Projection::version($relationship),
            'source' => Content::summary($record), 'capability' => $capability, 'relationship_ready' => $relationship->isActive() && $relationship->supports($capability) && $relationship->hasOutboundCredentials(),
            'outbound_execution_enabled' => (bool) config('relationship-api.outbound_execution_enabled', false), 'side_effects' => false]]);
    }

    public function push(Request $request, NexumRelationship $relationship, int $source, ExecuteRelationshipCommand $commands)
    {
        abort_unless($request->user()->tokenCan('relationships.providers.execute'), 403);
        $type = $request->route()->defaults['relationship_content'];
        $record = Content::source($relationship, $type, $source);
        $request->validate(['source_version' => 'required|string|max:100']);
        $request->attributes->set('relationship_authority', ['permission' => 'relationships.sync',
            'abilities' => ['relationships.sync', 'relationships.providers.execute'], 'source_type' => $type, 'source_id' => $source]);
        $result = $commands->handle($request, 'push_'.$type.'_'.$source, $relationship, function () use ($request, $relationship, $record, $type) {
            abort_unless(config('relationship-api.outbound_execution_enabled', false), 409, 'Relationship API outbound execution is disabled.');
            abort_unless(hash_equals(Projection::version($record->refresh()), $request->input('source_version')), 412, 'Source changed; obtain a fresh preview.');
            $scheme = parse_url((string) $relationship->remote_base_url);
            abort_unless(($scheme['scheme'] ?? '') === 'https' && empty($scheme['user']) && empty($scheme['pass']), 422, 'Trusted HTTPS without URL credentials is required.');
            $link = $type === 'documentation'
                ? app(SyncDocumentationToRelationship::class)->handle($record, $relationship)
                : app(SyncKnowledgeArticleToRelationship::class)->handle($record, $relationship);
            $state = match ($link->sync_status) {
                'synced' => 'remote_accepted', 'conflict' => 'conflict', 'unresolved', 'pending' => 'unresolved', default => 'failed'
            };

            return ['state' => $state, 'link' => Projection::link($link), 'source' => Content::summary($record),
                'remote_readback_verified' => false];
        }, true);

        return response()->json(['data' => $result], $result['reconciliation_required'] ? 202 : 200)->header('Cache-Control', 'no-store');
    }

    private function credentialAuthority(Request $request, bool $rotation = false): void
    {
        $needed = $rotation || collect(['inbound_token', 'outbound_token', 'webhook_secret'])->contains(fn ($field) => $request->exists($field));
        $abilities = ['relationships.manage'];
        if ($needed) {
            abort_unless($request->user()->tokenCan('relationships.credentials.write'), 403);
            $abilities[] = 'relationships.credentials.write';
        }
        $request->attributes->set('relationship_authority', ['permission' => 'relationships.manage', 'abilities' => $abilities]);
    }

    private function receipt(Request $request, RelationshipApiCommand $command): array
    {
        abort_unless((int) $command->actor_id === (int) $request->user()->id && hash_equals($command->token_hash, Access::tokenHash($request)), 404);
        Access::permit($request->user(), $command->authority['permission']);
        foreach ($command->authority['abilities'] as $ability) {
            abort_unless($request->user()->tokenCan($ability), 403);
        }
        if ($command->relationship_id) {
            NexumRelationship::findOrFail($command->relationship_id);
        }
        if (isset($command->authority['source_type'])) {
            Content::source(NexumRelationship::findOrFail($command->relationship_id), $command->authority['source_type'], $command->authority['source_id']);
        }

        return ExecuteRelationshipCommand::project($command);
    }

    public function operations(Request $request)
    {
        $rows = RelationshipApiCommand::where('actor_id', $request->user()->id)->where('token_hash', Access::tokenHash($request))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(Input::page($request));
        $rows->setCollection($rows->getCollection()->map(function ($row) use ($request) {
            try {
                return $this->receipt($request, $row);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException|\Illuminate\Database\Eloquent\ModelNotFoundException $error) {
                return null;
            }
        })->filter()->values());

        return response()->json($rows)->header('Cache-Control', 'no-store');
    }

    public function operation(Request $request, string $operation)
    {
        return response()->json(['data' => $this->receipt($request, RelationshipApiCommand::findOrFail($operation))])->header('Cache-Control', 'no-store');
    }
}
