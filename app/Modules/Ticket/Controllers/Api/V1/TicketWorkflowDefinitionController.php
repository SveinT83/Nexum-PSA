<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\EnsureDefaultEmailTemplates;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Models\TicketWorkflowVersion;
use App\Modules\Ticket\Services\TicketWorkflowDefinitionService;
use App\Modules\Ticket\Services\TicketWorkflowMigrationService;
use App\Modules\Ticket\Services\TicketWorkflowRequirementEvaluator;
use App\Modules\Ticket\Support\TicketWorkflowCustomerNotificationPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TicketWorkflowDefinitionController extends Controller
{
    public function index()
    {
        return response()->json(['data' => TicketWorkflow::query()
            ->with('publishedVersion')
            ->withCount(['states', 'transitions', 'versions'])
            ->orderByDesc('is_default')->orderBy('sort_order')->get()]);
    }

    public function catalog(TicketWorkflowRequirementEvaluator $requirements, EnsureDefaultEmailTemplates $emailDefaults)
    {
        $emailDefaults->handle();

        return response()->json(['data' => [
            'requirements' => $requirements->catalog(),
            'transition_triggers' => \App\Modules\Ticket\Support\TicketAction::transitionTriggerDefinitions(),
            'actions' => \App\Modules\Ticket\Support\TicketAction::definitions(),
            'customer_notification_channels' => TicketWorkflowCustomerNotificationPolicy::channelDefinitions(),
            'customer_notification_email_templates' => EmailTemplate::query()
                ->where('scope', 'tickets')->where('is_active', true)
                ->orderByDesc('is_default')->orderBy('name')->get(['key', 'name']),
        ]]);
    }

    public function show(TicketWorkflow $workflow, TicketWorkflowDefinitionService $definitions)
    {
        return response()->json(['data' => [
            'workflow' => $workflow->load('publishedVersion'),
            'draft_definition' => $definitions->fromWorkflow($workflow),
            'published_definition' => $workflow->publishedVersion?->definition,
        ]]);
    }

    public function store(Request $request, TicketWorkflowDefinitionService $definitions)
    {
        $data = $this->validatePayload($request);
        $workflow = TicketWorkflow::query()->create($data['workflow']);
        $definitions->saveDraft($workflow, $data['definition']);

        return $this->show($workflow->refresh(), $definitions)->setStatusCode(201);
    }

    public function update(Request $request, TicketWorkflow $workflow, TicketWorkflowDefinitionService $definitions)
    {
        $data = $this->validatePayload($request, $workflow);
        $workflow->update($data['workflow']);
        $definitions->saveDraft($workflow, $data['definition']);

        return $this->show($workflow->refresh(), $definitions);
    }

    public function publish(Request $request, TicketWorkflow $workflow, TicketWorkflowDefinitionService $definitions)
    {
        abort_unless($request->user()?->can('ticket.workflow_publish'), 403);
        $version = $definitions->publish($workflow, $request->user());

        return response()->json(['data' => $version], 201);
    }

    public function migrationPreview(Request $request, TicketWorkflow $workflow, TicketWorkflowMigrationService $migrations)
    {
        $data = $request->validate([
            'target_version_id' => ['nullable', 'integer', 'exists:ticket_workflow_versions,id'],
            'state_mapping' => ['nullable', 'array'],
            'state_mapping.*' => ['string', 'max:160'],
        ]);
        $target = ! empty($data['target_version_id'])
            ? TicketWorkflowVersion::query()->findOrFail((int) $data['target_version_id'])
            : $workflow->publishedVersion;

        return response()->json(['data' => $migrations->preview($workflow, $target)]);
    }

    public function migrate(Request $request, TicketWorkflow $workflow, TicketWorkflowMigrationService $migrations)
    {
        abort_unless($request->user()?->can('ticket.workflow_migrate'), 403);
        $data = $request->validate([
            'target_version_id' => ['required', 'integer', 'exists:ticket_workflow_versions,id'],
            'ticket_ids' => ['required', 'array', 'min:1'],
            'ticket_ids.*' => ['integer', 'exists:tickets,id'],
            'state_mapping' => ['nullable', 'array'],
            'state_mapping.*' => ['string', 'max:160'],
        ]);
        $target = TicketWorkflowVersion::query()->findOrFail((int) $data['target_version_id']);
        $count = $migrations->migrate($workflow, $target, array_map('intval', $data['ticket_ids']), $request->user());

        return response()->json(['data' => ['migrated_count' => $count, 'target_version_id' => $target->id, 'placement_mode' => 'automatic']]);
    }

    /** @return array{workflow: array<string, mixed>, definition: array<string, mixed>} */
    private function validatePayload(Request $request, ?TicketWorkflow $workflow = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:ticket_workflows,slug,'.($workflow?->id ?? 'NULL')],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'definition' => ['required', 'array'],
            'definition.states' => ['required', 'array', 'min:1'],
            'definition.transitions' => ['nullable', 'array'],
            'definition.escalation_paths' => ['nullable', 'array'],
        ]);

        return [
            'workflow' => [
                'name' => $data['name'],
                'slug' => $data['slug'] ?: Str::slug($data['name']),
                'description' => $data['description'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'is_default' => (bool) ($data['is_default'] ?? false),
                'sort_order' => (int) ($data['sort_order'] ?? 10),
                'definition_status' => 'draft',
            ],
            'definition' => $data['definition'],
        ];
    }
}
