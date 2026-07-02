<?php

namespace App\Modules\Relationship\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Knowledge\Article;
use App\Modules\Documentation\Models\Documentation;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Relationship\Actions\SyncDocumentationToRelationship;
use App\Modules\Relationship\Actions\SyncKnowledgeArticleToRelationship;
use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Support\RelationshipCapability;
use App\Modules\Relationship\Support\RelationshipDirection;
use App\Modules\Relationship\Support\RelationshipHealthStatus;
use App\Modules\Relationship\Support\RelationshipStatus;
use App\Modules\Relationship\Support\RelationshipType;
use App\Modules\Ticket\Models\TicketQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NexumRelationshipController extends Controller
{
    public function index(): View
    {
        return view('relationship::Admin.Relationships.index', [
            'relationships' => NexumRelationship::query()
                ->with(['client', 'vendor'])
                ->withCount(['syncLinks', 'syncEvents'])
                ->latest()
                ->paginate(20),
            'stats' => [
                'active' => NexumRelationship::query()->where('status', RelationshipStatus::ACTIVE)->count(),
                'failing' => NexumRelationship::query()->where('health_status', RelationshipHealthStatus::FAILING)->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('relationship::Admin.Relationships.form', $this->formData(new NexumRelationship([
            'direction' => RelationshipDirection::WE_ARE_PROVIDER,
            'relationship_type' => RelationshipType::CUSTOMER_PROVIDER,
            'status' => RelationshipStatus::DRAFT,
            'health_status' => RelationshipHealthStatus::UNKNOWN,
            'capabilities' => RelationshipCapability::defaults(),
            'ticket_policy' => ['auto_create_queue' => true],
            'documentation_policy' => ['two_way' => true],
            'attachment_policy' => ['max_mb' => 10, 'allowed_content_types' => []],
            'status_mapping' => [],
            'service_areas' => ['it'],
        ])));
    }

    public function store(Request $request): RedirectResponse
    {
        $relationship = new NexumRelationship;
        $data = $this->validated($request, $relationship);
        $plainInboundToken = null;

        $relationship->fill(Arr::except($data, ['outbound_token', 'webhook_secret', 'inbound_token']));
        $relationship->created_by = $request->user()?->id;
        $relationship->updated_by = $request->user()?->id;

        if (filled($data['outbound_token'] ?? null)) {
            $relationship->outbound_token_encrypted = $data['outbound_token'];
        }

        if (filled($data['webhook_secret'] ?? null)) {
            $relationship->webhook_secret_encrypted = $data['webhook_secret'];
        }

        if (filled($data['inbound_token'] ?? null)) {
            $plainInboundToken = $relationship->rotateInboundToken($data['inbound_token']);
        } elseif ($relationship->status === RelationshipStatus::ACTIVE) {
            $plainInboundToken = $relationship->rotateInboundToken();
        }

        $relationship->save();

        return redirect()
            ->route('tech.admin.system.relationships.show', $relationship)
            ->with('success', 'Nexum relationship created.')
            ->with('plain_inbound_token', $plainInboundToken);
    }

    public function show(NexumRelationship $relationship): View
    {
        $relationship->load(['client', 'vendor', 'syncLinks' => fn ($query) => $query->latest()->limit(25), 'syncEvents' => fn ($query) => $query->latest('occurred_at')->limit(25)]);

        return view('relationship::Admin.Relationships.show', array_merge($this->formData($relationship), [
            'relationship' => $relationship,
            'eligibleDocumentations' => $this->eligibleDocumentations($relationship),
            'eligibleArticles' => $this->eligibleArticles($relationship),
        ]));
    }

    public function edit(NexumRelationship $relationship): View
    {
        return view('relationship::Admin.Relationships.form', $this->formData($relationship));
    }

    public function update(Request $request, NexumRelationship $relationship): RedirectResponse
    {
        $data = $this->validated($request, $relationship);

        $relationship->fill(Arr::except($data, ['outbound_token', 'webhook_secret', 'inbound_token']));
        $relationship->updated_by = $request->user()?->id;

        if (filled($data['outbound_token'] ?? null)) {
            $relationship->outbound_token_encrypted = $data['outbound_token'];
        }

        if (filled($data['webhook_secret'] ?? null)) {
            $relationship->webhook_secret_encrypted = $data['webhook_secret'];
        }

        if (filled($data['inbound_token'] ?? null)) {
            $relationship->rotateInboundToken($data['inbound_token']);
        }

        $relationship->save();

        return redirect()
            ->route('tech.admin.system.relationships.show', $relationship)
            ->with('success', 'Nexum relationship updated.');
    }

    public function rotateSecrets(Request $request, NexumRelationship $relationship): RedirectResponse
    {
        $data = $request->validate([
            'rotate_inbound_token' => ['nullable', 'boolean'],
            'rotate_webhook_secret' => ['nullable', 'boolean'],
        ]);

        $plainInboundToken = null;

        if ($data['rotate_inbound_token'] ?? false) {
            $plainInboundToken = $relationship->rotateInboundToken();
        }

        if ($data['rotate_webhook_secret'] ?? false) {
            $relationship->webhook_secret_encrypted = str()->random(64);
            $relationship->token_rotated_at = now();
        }

        $relationship->updated_by = $request->user()?->id;
        $relationship->save();

        return back()
            ->with('success', 'Relationship secrets rotated.')
            ->with('plain_inbound_token', $plainInboundToken);
    }

    public function pushDocumentation(NexumRelationship $relationship, Documentation $documentation, SyncDocumentationToRelationship $sync): RedirectResponse
    {
        $sync->handle($documentation, $relationship);

        return back()->with('success', 'Documentation sync queued and sent.');
    }

    public function pushKnowledgeArticle(NexumRelationship $relationship, Article $article, SyncKnowledgeArticleToRelationship $sync): RedirectResponse
    {
        $sync->handle($article, $relationship);

        return back()->with('success', 'Knowledge article sync queued and sent.');
    }

    private function validated(Request $request, NexumRelationship $relationship): array
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'direction' => ['required', Rule::in(RelationshipDirection::values())],
            'relationship_type' => ['required', Rule::in(RelationshipType::values())],
            'client_id' => ['nullable', Rule::exists('clients', 'id')],
            'vendor_id' => ['nullable', Rule::exists('vendors', 'id')],
            'remote_base_url' => ['nullable', 'url', 'max:255'],
            'remote_instance_id' => ['nullable', 'string', 'max:255'],
            'remote_organization_name' => ['nullable', 'string', 'max:255'],
            'remote_organization_identifier' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(RelationshipStatus::values())],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', Rule::in(RelationshipCapability::values())],
            'ticket_queue_id' => ['nullable', Rule::exists('ticket_queues', 'id')->where('is_active', true)],
            'ticket_auto_create_queue' => ['nullable', 'boolean'],
            'documentation_two_way' => ['nullable', 'boolean'],
            'attachment_max_mb' => ['nullable', 'integer', 'min:1', 'max:100'],
            'attachment_allowed_content_types' => ['nullable', 'string', 'max:2000'],
            'service_areas' => ['nullable', 'string', 'max:2000'],
            'status_mapping' => ['nullable', 'string', 'max:4000'],
            'outbound_token' => ['nullable', 'string', 'max:2000'],
            'webhook_secret' => ['nullable', 'string', 'max:2000'],
            'inbound_token' => ['nullable', 'string', 'max:2000'],
        ]);

        $validator->after(function ($validator) use ($request, $relationship): void {
            $direction = $request->input('direction');
            $status = $request->input('status');
            $enabledCapabilities = collect($request->input('capabilities', []));

            if ($direction === RelationshipDirection::WE_ARE_PROVIDER && ! $request->filled('client_id')) {
                $validator->errors()->add('client_id', 'Provider relationships must be linked to a client.');
            }

            if ($direction === RelationshipDirection::WE_USE_PROVIDER && ! $request->filled('vendor_id')) {
                $validator->errors()->add('vendor_id', 'Upstream provider relationships must be linked to a vendor.');
            }

            if ($direction === RelationshipDirection::COLLABORATION && $status === RelationshipStatus::ACTIVE) {
                $validator->errors()->add('status', 'Collaboration relationships cannot be activated yet.');
            }

            if ($status === RelationshipStatus::ACTIVE) {
                if (! $request->filled('remote_base_url')) {
                    $validator->errors()->add('remote_base_url', 'Active relationships require a remote base URL.');
                }

                if (! $request->filled('remote_organization_name') && ! $request->filled('remote_organization_identifier')) {
                    $validator->errors()->add('remote_organization_name', 'Active relationships require remote organization identity.');
                }

                $hasInboundToken = $relationship->exists && filled($relationship->inbound_token_hash);
                if (! $hasInboundToken && ! $request->filled('inbound_token')) {
                    $validator->errors()->add('inbound_token', 'Active relationships require an inbound token.');
                }

                $hasWebhookSecret = $relationship->exists && filled($relationship->webhook_secret_encrypted);
                if (! $hasWebhookSecret && ! $request->filled('webhook_secret')) {
                    $validator->errors()->add('webhook_secret', 'Active relationships require a webhook signing secret.');
                }

                if ($enabledCapabilities->intersect([
                    RelationshipCapability::TICKET_SYNC,
                    RelationshipCapability::STATUS_SYNC,
                    RelationshipCapability::DOCUMENTATION_SYNC,
                    RelationshipCapability::KNOWLEDGE_SYNC,
                ])->isNotEmpty()) {
                    $hasOutboundToken = $relationship->exists && filled($relationship->outbound_token_encrypted);
                    if (! $hasOutboundToken && ! $request->filled('outbound_token')) {
                        $validator->errors()->add('outbound_token', 'Outbound sync capabilities require an outbound token.');
                    }
                }
            }
        });

        $validated = $validator->validate();
        $capabilities = RelationshipCapability::defaults();
        foreach (($validated['capabilities'] ?? []) as $capability) {
            $capabilities[$capability] = true;
        }

        return array_merge(Arr::only($validated, [
            'name',
            'direction',
            'relationship_type',
            'client_id',
            'vendor_id',
            'remote_base_url',
            'remote_instance_id',
            'remote_organization_name',
            'remote_organization_identifier',
            'status',
            'outbound_token',
            'webhook_secret',
            'inbound_token',
        ]), [
            'health_status' => $relationship->health_status ?: RelationshipHealthStatus::UNKNOWN,
            'capabilities' => $capabilities,
            'ticket_policy' => [
                'queue_id' => $validated['ticket_queue_id'] ?? null,
                'auto_create_queue' => (bool) ($validated['ticket_auto_create_queue'] ?? false),
            ],
            'documentation_policy' => [
                'two_way' => (bool) ($validated['documentation_two_way'] ?? false),
            ],
            'attachment_policy' => [
                'max_mb' => (int) ($validated['attachment_max_mb'] ?? 10),
                'allowed_content_types' => $this->lines($validated['attachment_allowed_content_types'] ?? ''),
            ],
            'status_mapping' => $this->mapping($validated['status_mapping'] ?? ''),
            'service_areas' => $this->lines($validated['service_areas'] ?? ''),
        ]);
    }

    private function formData(NexumRelationship $relationship): array
    {
        return [
            'relationship' => $relationship,
            'directions' => RelationshipDirection::labels(),
            'statuses' => RelationshipStatus::values(),
            'capabilities' => RelationshipCapability::values(),
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'client_number']),
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'vendor_code']),
            'queues' => TicketQueue::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'statusMappingText' => collect($relationship->status_mapping ?? [])->map(fn ($remote, $local) => $local.'='.$remote)->implode("\n"),
            'serviceAreasText' => implode("\n", $relationship->service_areas ?? []),
            'attachmentTypesText' => implode("\n", $relationship->attachment_policy['allowed_content_types'] ?? []),
        ];
    }

    private function eligibleDocumentations(NexumRelationship $relationship)
    {
        return Documentation::query()
            ->with(['category', 'client', 'site'])
            ->where('scope_type', '!=', 'internal')
            ->when($relationship->isProviderForClient(), fn ($query) => $query->where('client_id', $relationship->client_id))
            ->latest('updated_at')
            ->limit(20)
            ->get();
    }

    private function eligibleArticles(NexumRelationship $relationship)
    {
        return Article::query()
            ->where('visibility', '!=', 'internal')
            ->when($relationship->isProviderForClient(), fn ($query) => $query->where(function ($inner) use ($relationship): void {
                $inner->where('visibility', 'public')
                    ->orWhere('client_scope_id', $relationship->client_id);
            }))
            ->latest('updated_at')
            ->limit(20)
            ->get();
    }

    private function lines(string $value): array
    {
        return collect(preg_split('/[\r\n,]+/', $value))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function mapping(string $value): array
    {
        return collect(preg_split('/[\r\n]+/', $value))
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => str_contains($line, '='))
            ->mapWithKeys(function ($line): array {
                [$local, $remote] = array_map('trim', explode('=', $line, 2));

                return $local !== '' && $remote !== '' ? [$local => $remote] : [];
            })
            ->all();
    }
}
