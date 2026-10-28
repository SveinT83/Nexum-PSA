<?php

namespace App\Modules\Ticket\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Settings\CommonSetting;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\LegacyTicketRuleMutationBoundary;
use App\Modules\Ticket\Actions\SetPublishedTicketRuleEnabled;
use App\Modules\Ticket\Actions\UpdateDefaultTicketEmailAccount;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Models\TicketWorkflowVersion;
use App\Modules\Ticket\Queries\TicketRuleAdminIndexQuery;
use App\Modules\Ticket\Services\TicketRuleRuntimeGate;
use App\Modules\Ticket\Services\TicketWorkflowDefinitionService;
use App\Modules\Ticket\Services\TicketWorkflowMigrationService;
use App\Modules\Ticket\Services\TicketWorkflowRequirementEvaluator;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketPortalPolicy;
use App\Modules\Ticket\Support\TicketQuoteCostPolicy;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketSolutionPolicy;
use App\Modules\Ticket\Support\TicketWorkflowCustomerNotificationPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class TicketSettingsController extends Controller
{
    public function index(TicketSolutionPolicy $solutionPolicy, TicketPortalPolicy $portalPolicy, TicketQuoteCostPolicy $quoteCostPolicy): View
    {
        $emailAccounts = EmailAccount::query()
            ->where('is_active', true)
            ->orderBy('address')
            ->get();

        return view('ticket::Admin.Settings.index', [
            'emailAccounts' => $emailAccounts,
            'defaultTicketEmailAccount' => $emailAccounts->first(
                fn (EmailAccount $account) => in_array('tickets', (array) $account->defaults_for, true)
            ),
            'mergeSettings' => $this->ticketMergeSettings(),
            'solutionPolicy' => $solutionPolicy->settings(),
            'portalPolicy' => $portalPolicy->settings(),
            'quoteCostPolicy' => $quoteCostPolicy->settings(),
            'queues' => TicketQueue::withCount('tickets')->orderBy('sort_order')->orderBy('name')->get(),
            'types' => TicketType::withCount('tickets')->orderBy('sort_order')->orderBy('name')->get(),
            'statuses' => TicketStatus::withCount('tickets')->orderBy('sort_order')->orderBy('name')->get(),
            'priorities' => TicketPriority::withCount('tickets')->orderBy('level')->orderBy('sort_order')->orderBy('name')->get(),
            'slas' => Sla::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'documentationRequests' => TicketEvent::query()
                ->with(['ticket.client'])
                ->where('type', 'documentation_requested')
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }

    public function storeQueue(Request $request): RedirectResponse
    {
        app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->saveQueue($request);
        return back()->with('success', 'Ticket queue created.');
    }


    public function updateQueue(Request $request, TicketQueue $queue): RedirectResponse
    {
        app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->saveQueue($request, $queue);
        return back()->with('success', 'Ticket queue updated.');
    }


    public function destroyQueue(Request $request, TicketQueue $queue): RedirectResponse
    {

        app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->deleteQueue($request, $queue);
        return back()->with('success', 'Ticket queue deleted.');
    }


    public function storeType(Request $request): RedirectResponse
    {
        app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->saveType($request);
        return back()->with('success', 'Ticket type created.');
    }


    public function updateType(Request $request, TicketType $type): RedirectResponse
    {
        app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->saveType($request, $type);
        return back()->with('success', 'Ticket type updated.');
    }


    public function destroyType(Request $request, TicketType $type): RedirectResponse
    {

        app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->deleteType($request, $type);
        return back()->with('success', 'Ticket type deleted.');
    }


    public function storeStatus(Request $request): RedirectResponse
    {
        app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->saveStatus($request);
        return back()->with('success', 'Ticket status created.');
    }


    public function updateStatus(Request $request, TicketStatus $status): RedirectResponse
    {
        app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->saveStatus($request, $status);
        return back()->with('success', 'Ticket status updated.');
    }


    public function destroyStatus(Request $request, TicketStatus $status): RedirectResponse
    {

        app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->deleteStatus($request, $status);
        return back()->with('success', 'Ticket status deleted.');
    }


    public function storePriority(Request $request): RedirectResponse
    {
        app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->savePriority($request);
        return back()->with('success', 'Ticket priority created.');
    }


    public function updatePriority(Request $request, TicketPriority $priority): RedirectResponse
    {
        app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->savePriority($request, $priority);
        return back()->with('success', 'Ticket priority updated.');
    }


    public function destroyPriority(Request $request, TicketPriority $priority): RedirectResponse
    {

        app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->deletePriority($request, $priority);
        return back()->with('success', 'Ticket priority deleted.');
    }


    public function updateDefaultEmailAccount(
        Request $request,
        UpdateDefaultTicketEmailAccount $updateDefaultTicketEmailAccount
    ): RedirectResponse {
        app(\App\Modules\Ticket\Services\TicketSettingsEditor::class)->updateEmail($request);
        return back()->with('success', 'Default ticket email account updated.');
    }


    public function updateSolutionPolicy(Request $request, TicketSolutionPolicy $solutionPolicy): RedirectResponse
    {
        app(\App\Modules\Ticket\Services\TicketSettingsEditor::class)->updateSolution($request);
        return back()->with('success', 'Ticket solution policy updated.');
    }


    public function updatePortalPolicy(Request $request, TicketPortalPolicy $portalPolicy): RedirectResponse
    {
        app(\App\Modules\Ticket\Services\TicketSettingsEditor::class)->updatePortal($request);
        return back()->with('success', 'Ticket customer portal policy updated.');
    }


    public function updateQuoteCostPolicy(Request $request, TicketQuoteCostPolicy $quoteCostPolicy): RedirectResponse
    {
        app(\App\Modules\Ticket\Services\TicketSettingsEditor::class)->updateQuote($request);
        return back()->with('success', 'Ticket quote cost policy updated.');
    }


    public function updateMergeSettings(Request $request): RedirectResponse
    {
        app(\App\Modules\Ticket\Services\TicketSettingsEditor::class)->updateMerge($request);
        return back()->with('success', 'Ticket merge settings updated.');
    }


    public function rules(
        Request $request,
        TicketRuleAdminIndexQuery $rules,
        TicketRuleRuntimeGate $runtimeGate,
    ): View {
        $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'state' => ['nullable', 'string', 'in:active,inactive,draft'],
            'lifecycle' => ['nullable', 'string', 'in:legacy,published,disabled'],
            'sort' => ['nullable', 'string', 'in:name,weight,published_at,draft_updated_at,updated_at'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $publishedV2ToggleAvailable = false;
        try {
            if ($runtimeGate->enabled()) {
                $runtimeGate->requireExistingActor();
                $publishedV2ToggleAvailable = true;
            }
        } catch (RuntimeException) {
            // The index exposes no unsafe activation control when authority prerequisites drift.
        }

        return view('ticket::Admin.Settings.rules.index-builder', [
            'rules' => $rules->get($request),
            'publishedV2ToggleAvailable' => $publishedV2ToggleAvailable,
        ]);
    }

    public function createRule(): View
    {
        return view('ticket::Admin.Settings.rules.builder', [
            'ruleId' => null,
        ]);
    }

    public function storeRule(
        Request $request,
        LegacyTicketRuleMutationBoundary $ruleCatalog,
    ): RedirectResponse {
        $data = $this->validatedRule($request);

        $ruleCatalog->create($request->user(), $data + [
            'trigger' => TicketRule::TRIGGER_CREATE,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()->route('tech.admin.settings.tickets.rules')
            ->with('success', 'Ticket rule created.');
    }

    public function editRule(TicketRule $rule): View
    {
        return view('ticket::Admin.Settings.rules.builder', [
            'ruleId' => (int) $rule->id,
        ]);
    }

    public function updateRule(
        Request $request,
        TicketRule $rule,
        LegacyTicketRuleMutationBoundary $ruleCatalog,
    ): RedirectResponse {
        $ruleCatalog->update($request->user(), $rule, $this->validatedRule($request) + [
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()->route('tech.admin.settings.tickets.rules')
            ->with('success', 'Ticket rule updated.');
    }

    public function toggleRule(
        Request $request,
        TicketRule $rule,
        LegacyTicketRuleMutationBoundary $ruleCatalog,
        SetPublishedTicketRuleEnabled $publishedRules,
    ): RedirectResponse {
        $rule->load('publishedVersion');
        $publishedSchema = $rule->publishedVersion?->definition_schema_version;

        try {
            if (! $rule->publishedVersion && $rule->draft_payload_json !== null) {
                throw ValidationException::withMessages([
                    'rule' => 'Publish an immutable Ticket Rule version before changing runtime status.',
                ]);
            } elseif ((int) $publishedSchema === TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION) {
                $data = $request->validate([
                    'published_version_id' => ['required', 'integer', 'min:1'],
                    'definition_checksum' => ['required', 'string', 'regex:/\\A[0-9a-f]{64}\\z/'],
                    'expected_enabled' => ['required', 'boolean'],
                ]);
                $publishedRules->handle(
                    $rule,
                    $request->user(),
                    (int) $data['published_version_id'],
                    (string) $data['definition_checksum'],
                    (bool) $data['expected_enabled'],
                );
            } elseif ($rule->publishedVersion
                && (int) $publishedSchema !== TicketRuleDefinitionRegistry::SCHEMA_VERSION) {
                throw ValidationException::withMessages([
                    'rule' => 'This published Ticket Rule schema cannot be toggled from this catalogue.',
                ]);
            } else {
                // Schema-1 compatibility rows remain authoritative through the legacy fenced boundary.
                $ruleCatalog->toggle($request->user(), $rule);
            }
        } catch (RuntimeException) {
            return back()->withErrors([
                'rule' => 'The Ticket Rule status cannot be changed under the current runtime authority. Reload and verify the required capability and permissions.',
            ]);
        }

        return back()->with('success', 'Ticket rule status updated.');
    }

    public function destroyRule(
        Request $request,
        TicketRule $rule,
        LegacyTicketRuleMutationBoundary $ruleCatalog,
    ): RedirectResponse {
        $ruleCatalog->delete($request->user(), $rule);

        return back()->with('success', 'Ticket rule deleted.');
    }

    public function workflows(): View
    {
        app(EnsureTicketDefaults::class)->handle();

        return view()->exists('ticket::Admin.Settings.workflows.index')
            ? view('ticket::Admin.Settings.workflows.index', [
                'workflows' => TicketWorkflow::query()
                    ->with('publishedVersion')
                    ->withCount(['states', 'transitions'])
                    ->orderByDesc('is_default')
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(),
            ])
            : view('ticket::Admin.Settings.index');
    }

    public function createWorkflow(): View
    {
        app(EnsureTicketDefaults::class)->handle();

        return view('ticket::Admin.Settings.workflows.form', [
            'mode' => 'create',
            'workflow' => new TicketWorkflow([
                'name' => '',
                'is_active' => true,
                'is_default' => false,
                'sort_order' => 10,
            ]),
            'statuses' => TicketStatus::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'stateMap' => collect(),
            'transitions' => collect(),
            'triggerActions' => TicketAction::definitions(),
        ]);
    }

    public function storeWorkflow(Request $request, TicketWorkflowDefinitionService $definitions): RedirectResponse
    {
        $data = $this->validatedWorkflow($request);

        $workflow = DB::transaction(function () use ($data, $definitions): TicketWorkflow {
            $workflow = TicketWorkflow::create($data['workflow']);
            $definitions->saveDraft($workflow, $data['definition']);
            $this->ensureSingleDefaultWorkflow($workflow);

            return $workflow;
        });

        return redirect()->route('tech.admin.settings.tickets.workflows.edit', $workflow)
            ->with('success', 'Ticket workflow created.');
    }

    public function editWorkflow(TicketWorkflow $workflow, TicketWorkflowMigrationService $migrations): View
    {
        $workflow->load(['states', 'transitions']);

        return view('ticket::Admin.Settings.workflows.form', [
            'mode' => 'edit',
            'workflow' => $workflow,
            'statuses' => TicketStatus::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'stateMap' => $workflow->states,
            'transitions' => $workflow->transitions,
            'triggerActions' => TicketAction::definitions(),
            'migrationPreview' => $migrations->preview($workflow, $workflow->publishedVersion),
        ]);
    }

    public function updateWorkflow(Request $request, TicketWorkflow $workflow, TicketWorkflowDefinitionService $definitions): RedirectResponse
    {
        $data = $this->validatedWorkflow($request, $workflow);

        DB::transaction(function () use ($workflow, $data, $definitions): void {
            $workflow->update($data['workflow']);
            $definitions->saveDraft($workflow, $data['definition']);
            $this->ensureSingleDefaultWorkflow($workflow);
        });

        return redirect()->route('tech.admin.settings.tickets.workflows.edit', $workflow)
            ->with('success', 'Ticket workflow draft saved. Existing Tickets were not changed.');
    }

    public function publishWorkflow(Request $request, TicketWorkflow $workflow, TicketWorkflowDefinitionService $definitions): RedirectResponse
    {
        abort_unless($request->user()?->can('ticket.workflow_publish'), 403);
        $version = $definitions->publish($workflow, $request->user());

        return redirect()->route('tech.admin.settings.tickets.workflows.edit', $workflow->refresh())
            ->with('success', 'Workflow version '.$version->version.' published. Existing Tickets remain pinned to their current version.');
    }

    public function migrateWorkflowTickets(Request $request, TicketWorkflow $workflow, TicketWorkflowMigrationService $migrations): RedirectResponse
    {
        abort_unless($request->user()?->can('ticket.workflow_migrate'), 403);
        $data = $request->validate([
            'target_version_id' => ['required', 'integer', 'exists:ticket_workflow_versions,id'],
            'ticket_ids' => ['required', 'array', 'min:1'],
            'ticket_ids.*' => ['integer', 'exists:tickets,id'],
        ]);
        $target = TicketWorkflowVersion::query()->findOrFail((int) $data['target_version_id']);
        $count = $migrations->migrate($workflow, $target, array_map('intval', $data['ticket_ids']), $request->user());

        return redirect()->route('tech.admin.settings.tickets.workflows.edit', $workflow)
            ->with('success', $count.' active Ticket(s) automatically placed in workflow version '.$target->version.'.');
    }

    private function validatedQueue(Request $request, ?TicketQueue $queue = null): array
    {
        return app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->validatedQueue($request, $queue);
    }


    private function ticketMergeSettings(): array
    {
        return app(\App\Modules\Ticket\Support\TicketMergeSettings::class)->settings();
    }


    private function validatedWorkflow(Request $request, ?TicketWorkflow $workflow = null): array
    {
        $this->normalizeLegacyWorkflowInput($request);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:ticket_workflows,slug,'.($workflow?->id ?? 'NULL'),
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
            'states' => 'required|array|min:1',
            'states.*.state_key' => 'required|string|max:160|distinct',
            'states.*.ticket_status_id' => 'required|integer|exists:ticket_statuses,id',
            'states.*.name' => 'required|string|max:255',
            'states.*.is_initial' => 'nullable|boolean',
            'states.*.is_terminal' => 'nullable|boolean',
            'states.*.requirements' => 'nullable|array',
            'states.*.action_policy' => 'nullable|array',
            'states.*.action_policy.*.mode' => 'required|string|in:inherit,available,hidden,blocked,conditional',
            'states.*.action_policy.*.reason' => 'nullable|string|max:1000',
            'states.*.action_policy.*.requirements' => 'nullable|array',
            'states.*.assignment_policy' => 'nullable|array',
            'states.*.assignment_policy.strategy' => 'nullable|string|in:keep_if_eligible,auto,manual,unassigned',
            'states.*.assignment_policy.eligible_user_ids' => 'nullable|array',
            'states.*.assignment_policy.eligible_user_ids.*' => 'integer|exists:user_management,id',
            'states.*.commercial_policy.approved_scope_tolerance_ex_vat' => 'nullable|numeric|min:0|max:9999999999.99',
            'states.*.sort_order' => 'nullable|integer|min:0|max:100000',
            'transitions' => 'nullable|array',
            'transitions.*.transition_key' => 'required|string|max:160|distinct',
            'transitions.*.from_state_key' => 'required|string|max:160',
            'transitions.*.to_state_key' => 'required|string|max:160',
            'transitions.*.label' => 'required|string|max:255',
            'transitions.*.manual_enabled' => 'nullable|boolean',
            'transitions.*.trigger_actions' => 'nullable|array',
            'transitions.*.trigger_actions.*' => 'string|in:'.implode(',', array_keys(TicketAction::transitionTriggerDefinitions())),
            'transitions.*.requirements' => 'nullable|array',
            'transitions.*.customer_notification' => 'nullable|array',
            'transitions.*.customer_notification.enabled' => 'nullable|boolean',
            'transitions.*.customer_notification.channels' => 'nullable|array',
            'transitions.*.customer_notification.channels.*' => 'string|in:email,portal',
            'transitions.*.customer_notification.email_template_key' => 'nullable|string|max:100',
            'transitions.*.customer_notification.message' => 'nullable|string|max:2000',
            'transitions.*.sort_order' => 'nullable|integer|min:0|max:100000',
            'escalation_paths' => 'nullable|array',
            'escalation_paths.*.path_key' => 'required|string|max:160|distinct',
            'escalation_paths.*.label' => 'required|string|max:255',
            'escalation_paths.*.from_state_key' => 'required|string|max:160',
            'escalation_paths.*.target_workflow_id' => 'required|integer|exists:ticket_workflows,id',
            'escalation_paths.*.target_state_key' => 'required|string|max:160',
            'escalation_paths.*.target_queue_id' => 'nullable|integer|exists:ticket_queues,id',
            'escalation_paths.*.target_ticket_type_id' => 'nullable|integer|exists:ticket_types,id',
            'escalation_paths.*.mode' => 'required|string|in:optional,required',
            'escalation_paths.*.assignment_strategy' => 'required|string|in:keep_if_eligible,auto,fixed_user,manual,unassigned',
            'escalation_paths.*.fixed_user_id' => 'nullable|integer|exists:user_management,id',
            'escalation_paths.*.eligible_user_ids' => 'nullable|array',
            'escalation_paths.*.eligible_user_ids.*' => 'integer|exists:user_management,id',
            'escalation_paths.*.protected_actions' => 'nullable|array',
            'escalation_paths.*.protected_actions.*' => 'string|in:'.implode(',', array_keys(TicketAction::definitions())),
            'escalation_paths.*.requirements' => 'nullable|array',
        ]);

        $facts = array_keys(app(TicketWorkflowRequirementEvaluator::class)->catalog());
        $this->validateRequirementTrees($data, $facts);

        $slug = $data['slug'] ?: Str::slug($data['name']);
        $this->ensureUniqueSlug(TicketWorkflow::class, $slug, $workflow?->id, 'workflow');

        $states = collect($data['states'])
            ->map(function (array $state, int $index) {
                return [
                    'state_key' => $state['state_key'],
                    'ticket_status_id' => (int) $state['ticket_status_id'],
                    'name' => $state['name'],
                    'is_initial' => (bool) ($state['is_initial'] ?? false),
                    'is_terminal' => (bool) ($state['is_terminal'] ?? false),
                    'requirements' => $this->normalizedRequirementTree($state['requirements'] ?? []),
                    'action_policy' => collect($state['action_policy'] ?? [])->map(fn (array $policy) => [
                        'mode' => $policy['mode'] ?? 'inherit',
                        'reason' => $policy['reason'] ?? null,
                        'requirements' => $this->normalizedRequirementTree($policy['requirements'] ?? []),
                    ])->all(),
                    'assignment_policy' => [
                        'strategy' => data_get($state, 'assignment_policy.strategy', 'keep_if_eligible'),
                        'eligible_user_ids' => collect(data_get($state, 'assignment_policy.eligible_user_ids', []))->map(fn ($id) => (int) $id)->values()->all(),
                    ],
                    'commercial_policy' => [
                        'approved_scope_tolerance_ex_vat' => (float) data_get($state, 'commercial_policy.approved_scope_tolerance_ex_vat', 0),
                    ],
                    'sort_order' => (int) ($state['sort_order'] ?? (($index + 1) * 10)),
                ];
            })
            ->values()
            ->all();

        if ($states === []) {
            throw ValidationException::withMessages(['states' => 'A workflow must include at least one state.']);
        }

        if (collect($states)->where('is_initial', true)->count() !== 1) {
            throw ValidationException::withMessages(['states' => 'A workflow must have exactly one initial state.']);
        }

        $enabledStateKeys = collect($states)->pluck('state_key')->all();
        $seenTransitions = [];

        $transitions = collect($data['transitions'] ?? [])
            ->map(function (array $transition, int $index) use ($enabledStateKeys, &$seenTransitions) {
                $from = $transition['from_state_key'];
                $to = $transition['to_state_key'];

                if (! in_array($from, $enabledStateKeys, true) || ! in_array($to, $enabledStateKeys, true) || $from === $to) {
                    throw ValidationException::withMessages(['transitions' => 'Transitions must use two different enabled states.']);
                }

                $key = $transition['transition_key'];

                if (isset($seenTransitions[$key])) {
                    throw ValidationException::withMessages(['transitions' => 'Duplicate workflow transitions are not allowed.']);
                }

                $seenTransitions[$key] = true;

                return [
                    'transition_key' => $transition['transition_key'],
                    'from_state_key' => $from,
                    'to_state_key' => $to,
                    'label' => $transition['label'],
                    'manual_enabled' => (bool) ($transition['manual_enabled'] ?? true),
                    'trigger_actions' => array_values($transition['trigger_actions'] ?? []),
                    'requirements' => $this->normalizedRequirementTree($transition['requirements'] ?? []),
                    'customer_notification' => TicketWorkflowCustomerNotificationPolicy::normalize($transition['customer_notification'] ?? null),
                    'sort_order' => (int) ($transition['sort_order'] ?? (($index + 1) * 10)),
                ];
            })
            ->values()
            ->all();

        $escalations = collect($data['escalation_paths'] ?? [])->map(fn (array $path) => [
            'path_key' => $path['path_key'],
            'label' => $path['label'],
            'from_state_key' => $path['from_state_key'],
            'target_workflow_id' => (int) $path['target_workflow_id'],
            'target_state_key' => $path['target_state_key'],
            'target_queue_id' => filled($path['target_queue_id'] ?? null) ? (int) $path['target_queue_id'] : null,
            'target_ticket_type_id' => filled($path['target_ticket_type_id'] ?? null) ? (int) $path['target_ticket_type_id'] : null,
            'mode' => $path['mode'],
            'assignment_strategy' => $path['assignment_strategy'],
            'fixed_user_id' => filled($path['fixed_user_id'] ?? null) ? (int) $path['fixed_user_id'] : null,
            'eligible_user_ids' => collect($path['eligible_user_ids'] ?? [])->map(fn ($id) => (int) $id)->values()->all(),
            'protected_actions' => array_values($path['protected_actions'] ?? []),
            'requirements' => $this->normalizedRequirementTree($path['requirements'] ?? []),
        ])->values()->all();

        return [
            'workflow' => [
                'name' => $data['name'],
                'slug' => $slug,
                'description' => $data['description'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? false),
                'is_default' => (bool) ($data['is_default'] ?? false),
                'sort_order' => (int) ($data['sort_order'] ?? 10),
                'definition_status' => 'draft',
            ],
            'definition' => [
                'schema_version' => TicketWorkflowDefinitionService::CURRENT_SCHEMA_VERSION,
                'states' => $states,
                'transitions' => $transitions,
                'escalation_paths' => $escalations,
            ],
        ];
    }

    private function normalizedRequirementTree(array $tree): array
    {
        return [
            'match' => in_array(($tree['match'] ?? 'all'), ['all', 'any'], true) ? ($tree['match'] ?? 'all') : 'all',
            'groups' => collect($tree['groups'] ?? [])->map(fn (array $group) => [
                'match' => in_array(($group['match'] ?? 'all'), ['all', 'any'], true) ? ($group['match'] ?? 'all') : 'all',
                'conditions' => collect($group['conditions'] ?? [])->map(fn (array $condition) => [
                    'fact' => $condition['fact'],
                    'operator' => $condition['operator'],
                    'value' => filled($condition['value'] ?? null) ? $condition['value'] : null,
                    'schema_version' => (int) ($condition['schema_version'] ?? 1),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Convert the original status-indexed workflow form into the versioned
     * state-key definition. This keeps existing integrations and bookmarked
     * admin forms working while all new saves use the grouped rule schema.
     */
    private function normalizeLegacyWorkflowInput(Request $request): void
    {
        $inputStates = $request->input('states', []);
        if (! is_array($inputStates) || $inputStates === []) {
            return;
        }

        $alreadyVersioned = collect($inputStates)->every(fn ($state) => is_array($state)
            && filled($state['state_key'] ?? null)
            && filled($state['ticket_status_id'] ?? null));
        if ($alreadyVersioned) {
            return;
        }

        $statusIds = collect($inputStates)->map(fn (array $state, $key) => (int) ($state['ticket_status_id'] ?? $key))->filter()->values();
        $statuses = TicketStatus::query()->whereIn('id', $statusIds)->get()->keyBy('id');
        $stateKeysByStatus = [];
        $states = [];

        foreach ($inputStates as $key => $state) {
            if (! is_array($state) || ! (bool) ($state['enabled'] ?? true)) {
                continue;
            }

            $statusId = (int) ($state['ticket_status_id'] ?? $key);
            $status = $statuses->get($statusId);
            if (! $status) {
                continue;
            }

            $stateKey = (string) ($state['state_key'] ?? $status->slug);
            $stateKeysByStatus[$statusId] = $stateKey;
            $states[] = [
                'state_key' => $stateKey,
                'ticket_status_id' => $statusId,
                'name' => $state['name'] ?? $status->name,
                'is_initial' => (bool) ($state['is_initial'] ?? false),
                'is_terminal' => (bool) ($state['is_terminal'] ?? $status->is_closed),
                'requirements' => $this->legacyRequirementTree($state),
                'action_policy' => [],
                'assignment_policy' => ['strategy' => 'keep_if_eligible', 'eligible_user_ids' => []],
                'commercial_policy' => ['approved_scope_tolerance_ex_vat' => 0],
                'sort_order' => (int) ($state['sort_order'] ?? ((count($states) + 1) * 10)),
            ];
        }

        $transitions = [];
        foreach ((array) $request->input('transitions', []) as $index => $transition) {
            if (! is_array($transition) || ! (bool) ($transition['enabled'] ?? true)) {
                continue;
            }

            $from = $stateKeysByStatus[(int) ($transition['from_status_id'] ?? 0)] ?? null;
            $to = $stateKeysByStatus[(int) ($transition['to_status_id'] ?? 0)] ?? null;
            if (! $from || ! $to) {
                continue;
            }

            $transitions[] = [
                'transition_key' => $transition['transition_key'] ?? $from.'-to-'.$to,
                'from_state_key' => $from,
                'to_state_key' => $to,
                'label' => $transition['label'] ?? 'Move to next step',
                'manual_enabled' => (bool) ($transition['manual_enabled'] ?? true),
                'trigger_actions' => array_values($transition['trigger_actions'] ?? []),
                'requirements' => $this->legacyRequirementTree($transition),
                'sort_order' => (int) ($transition['sort_order'] ?? (($index + 1) * 10)),
            ];
        }

        $request->merge([
            'states' => $states,
            'transitions' => $transitions,
            'escalation_paths' => (array) $request->input('escalation_paths', []),
        ]);
    }

    /** @return array{match: string, groups: array<int, array<string, mixed>>} */
    private function legacyRequirementTree(array $source): array
    {
        if (! empty(data_get($source, 'requirements.groups'))) {
            return $this->normalizedRequirementTree($source['requirements']);
        }

        $conditions = collect([
            'ticket.internal_note' => (bool) ($source['requires_note'] ?? false),
            'ticket.technician_response' => (bool) ($source['requires_response'] ?? false),
            'ticket.solution' => (bool) ($source['requires_resolution'] ?? false),
            'ticket.knowledge_follow_up' => (bool) ($source['requires_knowledge_update'] ?? false),
        ])->filter()->keys()->map(fn (string $fact) => [
            'fact' => $fact,
            'operator' => 'is_true',
            'value' => null,
            'schema_version' => 1,
        ])->values()->all();

        return [
            'match' => 'all',
            'groups' => $conditions === [] ? [] : [[
                'match' => 'all',
                'conditions' => $conditions,
            ]],
        ];
    }

    private function validateRequirementTrees(array $data, array $facts): void
    {
        $trees = collect($data['states'])->flatMap(function (array $state): array {
            return array_merge(
                [$state['requirements'] ?? []],
                collect($state['action_policy'] ?? [])->pluck('requirements')->all(),
            );
        })->merge(collect($data['transitions'] ?? [])->pluck('requirements'))
            ->merge(collect($data['escalation_paths'] ?? [])->pluck('requirements'));

        foreach ($trees as $tree) {
            foreach (($tree['groups'] ?? []) as $group) {
                foreach (($group['conditions'] ?? []) as $condition) {
                    if (! in_array($condition['fact'] ?? null, $facts, true)) {
                        throw ValidationException::withMessages(['requirements' => 'A workflow requirement is no longer available.']);
                    }
                    if (! in_array($condition['operator'] ?? null, ['is_true', 'is_false', 'equals', 'not_equals', 'contains', 'present', 'not_present', 'greater_or_equal', 'less_or_equal', 'gte', 'lte'], true)) {
                        throw ValidationException::withMessages(['requirements' => 'A workflow requirement has an unsupported operator.']);
                    }
                }
            }
        }
    }

    private function ensureSingleDefaultWorkflow(TicketWorkflow $workflow): void
    {
        if (! $workflow->is_default) {
            return;
        }

        TicketWorkflow::query()
            ->whereKeyNot($workflow->id)
            ->update(['is_default' => false]);
    }

    private function validatedType(Request $request, ?TicketType $type = null): array
    {
        return app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->validatedType($request, $type);
    }


    private function validatedStatus(Request $request, ?TicketStatus $status = null): array
    {
        return app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->validatedStatus($request, $status);
    }


    private function validatedPriority(Request $request, ?TicketPriority $priority = null): array
    {
        return app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->validatedPriority($request, $priority);
    }


    private function validatedRule(Request $request): array
    {
        return app(\App\Modules\Ticket\Services\TicketLegacyRuleForm::class)->validatedRule($request);
    }

    private function validateRuleActionTargets(array $actions): void
    {
        foreach ($actions as $action) {
            $exists = match ($action['type']) {
                'set_ticket_type' => TicketType::whereKey($action['value'])->exists(),
                'set_queue' => TicketQueue::whereKey($action['value'])->exists(),
                'set_priority' => TicketPriority::whereKey($action['value'])->exists(),
                'set_sla' => Sla::whereKey($action['value'])->exists(),
                'set_category' => Category::forTickets()->active()->whereKey($action['value'])->exists(),
                'add_tag' => Tag::where('active', true)->whereKey($action['value'])->exists(),
                'emit_signal' => $this->normalizeSignalType($action['value']) !== '',
                default => false,
            };

            if (! $exists) {
                throw ValidationException::withMessages([
                    'actions' => $action['type'] === 'emit_signal'
                        ? 'Emit Signal action requires a signal type.'
                        : 'Rule action target does not exist.',
                ]);
            }
        }
    }

    private function normalizeSignalType(mixed $value): string
    {
        return str((string) $value)
            ->trim()
            ->lower()
            ->replace([' ', '-'], '_')
            ->toString();
    }

    private function ensureSingleDefaultQueue(?TicketQueue $defaultQueue = null): void
    {
        app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->ensureSingleDefaultQueue($defaultQueue);
    }


    private function ensureSingleDefaultStatus(?TicketStatus $defaultStatus = null): void
    {
        app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->ensureSingleDefaultStatus($defaultStatus);
    }


    private function ensureSingleDefaultPriority(?TicketPriority $defaultPriority = null): void
    {
        app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->ensureSingleDefaultPriority($defaultPriority);
    }


    private function ensureUniqueSlug(string $modelClass, string $slug, ?int $ignoreId, string $field): void
    {
        app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->ensureUniqueSlug($modelClass, $slug, $ignoreId, $field);
    }


    private function ticketRuleReferences(string $actionType, int $id): bool
    {
        return app(\App\Modules\Ticket\Services\TicketCatalogEditor::class)->ticketRuleReferences($actionType, $id);
    }


}
