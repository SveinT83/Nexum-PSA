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
        $data = app(\App\Modules\Relationship\Actions\RelationshipConfiguration::class)->validateForm($request, $relationship);
        $plainInboundToken = app(\App\Modules\Relationship\Actions\SaveRelationshipConfiguration::class)
            ->handle($relationship, $data, $request->user());

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
        $data = app(\App\Modules\Relationship\Actions\RelationshipConfiguration::class)->validateForm($request, $relationship);

        app(\App\Modules\Relationship\Actions\SaveRelationshipConfiguration::class)
            ->handle($relationship, $data, $request->user());

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

        $plainInboundToken = app(\App\Modules\Relationship\Actions\SaveRelationshipConfiguration::class)
            ->rotate($relationship, $data, $request->user());

        return back()
            ->with('success', 'Relationship secrets rotated.')
            ->with('plain_inbound_token', $plainInboundToken);
    }

    public function pushDocumentation(NexumRelationship $relationship, Documentation $documentation, SyncDocumentationToRelationship $sync): RedirectResponse
    {
        \App\Modules\Relationship\Support\RelationshipContentAccess::source($relationship, 'documentation', $documentation->id);
        $link = $sync->handle($documentation, $relationship);

        return back()->with($link->sync_status === 'synced' ? 'success' : 'warning', $link->sync_status === 'synced' ? 'Remote Nexum accepted the documentation.' : 'Documentation sync requires review: '.$link->sync_status.'.');
    }

    public function pushKnowledgeArticle(NexumRelationship $relationship, Article $article, SyncKnowledgeArticleToRelationship $sync): RedirectResponse
    {
        \App\Modules\Relationship\Support\RelationshipContentAccess::source($relationship, 'knowledge', $article->id);
        $link = $sync->handle($article, $relationship);

        return back()->with($link->sync_status === 'synced' ? 'success' : 'warning', $link->sync_status === 'synced' ? 'Remote Nexum accepted the knowledge article.' : 'Knowledge sync requires review: '.$link->sync_status.'.');
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
}
