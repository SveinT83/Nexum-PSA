<?php

namespace App\Modules\Ticket\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Settings\CommonSetting;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Actions\UpdateDefaultTicketEmailAccount;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Models\TicketWorkflowTransition;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketSolutionPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TicketSettingsController extends Controller
{
    public function index(TicketSolutionPolicy $solutionPolicy): View
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
        $data = $this->validatedQueue($request);

        TicketQueue::create($data);

        $this->ensureSingleDefaultQueue();

        return back()->with('success', 'Ticket queue created.');
    }

    public function updateQueue(Request $request, TicketQueue $queue): RedirectResponse
    {
        $queue->update($this->validatedQueue($request, $queue));

        $this->ensureSingleDefaultQueue($queue);

        return back()->with('success', 'Ticket queue updated.');
    }

    public function destroyQueue(TicketQueue $queue): RedirectResponse
    {
        if ($queue->tickets()->exists() || $this->ticketRuleReferences('set_queue', $queue->id)) {
            return back()->withErrors(['queue' => 'Queue cannot be deleted while tickets use it.']);
        }

        $queue->delete();

        return back()->with('success', 'Ticket queue deleted.');
    }

    public function storeType(Request $request): RedirectResponse
    {
        TicketType::create($this->validatedType($request));

        return back()->with('success', 'Ticket type created.');
    }

    public function updateType(Request $request, TicketType $type): RedirectResponse
    {
        $type->update($this->validatedType($request, $type));

        return back()->with('success', 'Ticket type updated.');
    }

    public function destroyType(TicketType $type): RedirectResponse
    {
        if (! $type->is_deletable || $type->tickets()->exists() || $this->ticketRuleReferences('set_ticket_type', $type->id)) {
            return back()->withErrors(['type' => 'Ticket type cannot be deleted while it is protected or in use.']);
        }

        $type->delete();

        return back()->with('success', 'Ticket type deleted.');
    }

    public function storeStatus(Request $request): RedirectResponse
    {
        // Status records control lifecycle behavior, so default normalization stays in this controller.
        $data = $this->validatedStatus($request);

        TicketStatus::create($data);

        $this->ensureSingleDefaultStatus();

        return back()->with('success', 'Ticket status created.');
    }

    public function updateStatus(Request $request, TicketStatus $status): RedirectResponse
    {
        $status->update($this->validatedStatus($request, $status));

        $this->ensureSingleDefaultStatus($status);

        return back()->with('success', 'Ticket status updated.');
    }

    public function destroyStatus(TicketStatus $status): RedirectResponse
    {
        if ($status->tickets()->exists()) {
            return back()->withErrors(['status' => 'Ticket status cannot be deleted while tickets use it.']);
        }

        $status->delete();

        return back()->with('success', 'Ticket status deleted.');
    }

    public function storePriority(Request $request): RedirectResponse
    {
        // Priority levels are shared by ticket sorting, forms, and Ticket Rule actions.
        $data = $this->validatedPriority($request);

        TicketPriority::create($data);

        $this->ensureSingleDefaultPriority();

        return back()->with('success', 'Ticket priority created.');
    }

    public function updatePriority(Request $request, TicketPriority $priority): RedirectResponse
    {
        $priority->update($this->validatedPriority($request, $priority));

        $this->ensureSingleDefaultPriority($priority);

        return back()->with('success', 'Ticket priority updated.');
    }

    public function destroyPriority(TicketPriority $priority): RedirectResponse
    {
        if ($priority->tickets()->exists() || $this->ticketRuleReferences('set_priority', $priority->id)) {
            return back()->withErrors(['priority' => 'Ticket priority cannot be deleted while tickets or rules use it.']);
        }

        $priority->delete();

        return back()->with('success', 'Ticket priority deleted.');
    }

    public function updateDefaultEmailAccount(
        Request $request,
        UpdateDefaultTicketEmailAccount $updateDefaultTicketEmailAccount
    ): RedirectResponse {
        $data = $request->validate([
            'email_account_id' => 'nullable|exists:email_accounts,id',
        ]);

        $selectedAccount = isset($data['email_account_id'])
            ? EmailAccount::where('is_active', true)->findOrFail($data['email_account_id'])
            : null;

        $updateDefaultTicketEmailAccount->handle($selectedAccount);

        return back()->with('success', 'Default ticket email account updated.');
    }

    public function updateSolutionPolicy(Request $request, TicketSolutionPolicy $solutionPolicy): RedirectResponse
    {
        $data = $request->validate([
            'allow_internal_solution_notes' => 'nullable|boolean',
        ]);

        $solutionPolicy->update([
            'allow_internal_solution_notes' => (bool) ($data['allow_internal_solution_notes'] ?? false),
        ]);

        return back()->with('success', 'Ticket solution policy updated.');
    }

    public function updateMergeSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'auto_merge_enabled' => 'nullable|boolean',
            'ai_merge_enabled' => 'nullable|boolean',
            'ai_similarity_threshold' => 'required|integer|min:70|max:100',
        ]);

        $settings = [
            'auto_merge_enabled' => (bool) ($data['auto_merge_enabled'] ?? false),
            'ai_merge_enabled' => (bool) ($data['ai_merge_enabled'] ?? false),
            'ai_similarity_threshold' => (int) $data['ai_similarity_threshold'],
        ];

        foreach ($settings as $name => $value) {
            CommonSetting::updateOrCreate(
                ['type' => 'ticket_merge', 'name' => $name],
                [
                    'description' => 'Ticket merge automation setting.',
                    'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                ],
            );
        }

        return back()->with('success', 'Ticket merge settings updated.');
    }

    public function rules(): View
    {
        return view('ticket::Admin.Settings.rules.index', [
            'rules' => TicketRule::query()->orderBy('weight')->orderBy('id')->get(),
            'types' => TicketType::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'queues' => TicketQueue::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'priorities' => TicketPriority::where('is_active', true)->orderBy('level')->get(),
            'slas' => Sla::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'categories' => Category::forTickets()->active()->orderBy('name')->get(),
            'tags' => Tag::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function createRule(): View
    {
        return view('ticket::Admin.Settings.rules.create', [
            'rule' => new TicketRule([
                'trigger' => TicketRule::TRIGGER_CREATE,
                'weight' => 10,
                'is_active' => true,
                'stop_processing' => false,
                'conditions_json' => [['field' => 'channel', 'operator' => 'equals', 'value' => 'email']],
                'actions_json' => [['type' => 'set_ticket_type', 'value' => '']],
            ]),
            'mode' => 'create',
            'types' => TicketType::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'queues' => TicketQueue::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'priorities' => TicketPriority::where('is_active', true)->orderBy('level')->get(),
            'slas' => Sla::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'categories' => Category::forTickets()->active()->orderBy('name')->get(),
            'tags' => Tag::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $data = $this->validatedRule($request);

        TicketRule::create($data + [
            'trigger' => TicketRule::TRIGGER_CREATE,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()->route('tech.admin.settings.tickets.rules')
            ->with('success', 'Ticket rule created.');
    }

    public function editRule(TicketRule $rule): View
    {
        return view('ticket::Admin.Settings.rules.create', [
            'rule' => $rule,
            'mode' => 'edit',
            'types' => TicketType::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'queues' => TicketQueue::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'priorities' => TicketPriority::where('is_active', true)->orderBy('level')->get(),
            'slas' => Sla::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'categories' => Category::forTickets()->active()->orderBy('name')->get(),
            'tags' => Tag::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function updateRule(Request $request, TicketRule $rule): RedirectResponse
    {
        $rule->update($this->validatedRule($request) + [
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()->route('tech.admin.settings.tickets.rules')
            ->with('success', 'Ticket rule updated.');
    }

    public function toggleRule(TicketRule $rule): RedirectResponse
    {
        $rule->forceFill(['is_active' => ! $rule->is_active])->save();

        return back()->with('success', 'Ticket rule status updated.');
    }

    public function destroyRule(TicketRule $rule): RedirectResponse
    {
        $rule->delete();

        return back()->with('success', 'Ticket rule deleted.');
    }

    public function workflows(): View
    {
        app(EnsureTicketDefaults::class)->handle();

        return view()->exists('ticket::Admin.Settings.workflows.index')
            ? view('ticket::Admin.Settings.workflows.index', [
                'workflows' => TicketWorkflow::query()
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

    public function storeWorkflow(Request $request): RedirectResponse
    {
        $data = $this->validatedWorkflow($request);

        $workflow = TicketWorkflow::create($data['workflow']);

        $this->syncWorkflowStates($workflow, $data['states']);
        $this->syncWorkflowTransitions($workflow, $data['transitions']);
        $this->ensureSingleDefaultWorkflow($workflow);

        return redirect()->route('tech.admin.settings.tickets.workflows.edit', $workflow)
            ->with('success', 'Ticket workflow created.');
    }

    public function editWorkflow(TicketWorkflow $workflow): View
    {
        $workflow->load(['states', 'transitions']);

        return view('ticket::Admin.Settings.workflows.form', [
            'mode' => 'edit',
            'workflow' => $workflow,
            'statuses' => TicketStatus::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'stateMap' => $workflow->states->keyBy('ticket_status_id'),
            'transitions' => $workflow->transitions,
            'triggerActions' => TicketAction::definitions(),
        ]);
    }

    public function updateWorkflow(Request $request, TicketWorkflow $workflow): RedirectResponse
    {
        $data = $this->validatedWorkflow($request, $workflow);

        $workflow->update($data['workflow']);

        $this->syncWorkflowStates($workflow, $data['states']);
        $this->syncWorkflowTransitions($workflow, $data['transitions']);
        $this->ensureSingleDefaultWorkflow($workflow);

        return redirect()->route('tech.admin.settings.tickets.workflows.edit', $workflow)
            ->with('success', 'Ticket workflow updated.');
    }

    private function validatedQueue(Request $request, ?TicketQueue $queue = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:ticket_queues,slug,' . ($queue?->id ?? 'NULL'),
            'description' => 'nullable|string',
            'email_address' => 'nullable|email|max:255',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ]);

        $data['slug'] = $data['slug'] ?: Str::slug($data['name']);
        $this->ensureUniqueSlug(TicketQueue::class, $data['slug'], $queue?->id, 'queue');
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    private function ticketMergeSettings(): array
    {
        $settings = CommonSetting::query()
            ->where('type', 'ticket_merge')
            ->pluck('value', 'name');

        return [
            'auto_merge_enabled' => $settings->get('auto_merge_enabled', '0') === '1',
            'ai_merge_enabled' => $settings->get('ai_merge_enabled', '0') === '1',
            'ai_similarity_threshold' => (int) ($settings->get('ai_similarity_threshold', '90') ?: 90),
        ];
    }

    /**
     * @return array{workflow: array<string, mixed>, states: array<int, array<string, mixed>>, transitions: array<int, array<string, mixed>>}
     */
    private function validatedWorkflow(Request $request, ?TicketWorkflow $workflow = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:ticket_workflows,slug,' . ($workflow?->id ?? 'NULL'),
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
            'states' => 'required|array|min:1',
            'states.*.enabled' => 'nullable|boolean',
            'states.*.name' => 'nullable|string|max:255',
            'states.*.is_initial' => 'nullable|boolean',
            'states.*.is_terminal' => 'nullable|boolean',
            'states.*.requires_note' => 'nullable|boolean',
            'states.*.requires_response' => 'nullable|boolean',
            'states.*.requires_resolution' => 'nullable|boolean',
            'states.*.requires_knowledge_update' => 'nullable|boolean',
            'states.*.sort_order' => 'nullable|integer|min:0|max:100000',
            'transitions' => 'nullable|array',
            'transitions.*.enabled' => 'nullable|boolean',
            'transitions.*.from_status_id' => 'nullable|integer|exists:ticket_statuses,id',
            'transitions.*.to_status_id' => 'nullable|integer|exists:ticket_statuses,id',
            'transitions.*.label' => 'nullable|string|max:255',
            'transitions.*.manual_enabled' => 'nullable|boolean',
            'transitions.*.trigger_actions' => 'nullable|array',
            'transitions.*.trigger_actions.*' => 'string|in:' . implode(',', array_keys(TicketAction::definitions())),
            'transitions.*.requires_note' => 'nullable|boolean',
            'transitions.*.requires_response' => 'nullable|boolean',
            'transitions.*.requires_resolution' => 'nullable|boolean',
            'transitions.*.requires_knowledge_update' => 'nullable|boolean',
            'transitions.*.sort_order' => 'nullable|integer|min:0|max:100000',
        ]);

        $slug = $data['slug'] ?: Str::slug($data['name']);
        $this->ensureUniqueSlug(TicketWorkflow::class, $slug, $workflow?->id, 'workflow');

        $states = collect($data['states'])
            ->filter(fn (array $state) => (bool) ($state['enabled'] ?? false))
            ->map(function (array $state, int|string $statusId) {
                return [
                    'ticket_status_id' => (int) $statusId,
                    'name' => $state['name'] ?: TicketStatus::find($statusId)?->name ?: 'State',
                    'is_initial' => (bool) ($state['is_initial'] ?? false),
                    'is_terminal' => (bool) ($state['is_terminal'] ?? false),
                    'requires_note' => (bool) ($state['requires_note'] ?? false),
                    'requires_response' => (bool) ($state['requires_response'] ?? false),
                    'requires_resolution' => (bool) ($state['requires_resolution'] ?? false),
                    'requires_knowledge_update' => (bool) ($state['requires_knowledge_update'] ?? false),
                    'sort_order' => (int) ($state['sort_order'] ?? 10),
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

        $enabledStatusIds = collect($states)->pluck('ticket_status_id')->all();
        $seenTransitions = [];

        $transitions = collect($data['transitions'] ?? [])
            ->filter(fn (array $transition) => (bool) ($transition['enabled'] ?? false))
            ->map(function (array $transition, int $index) use ($enabledStatusIds, &$seenTransitions) {
                $from = (int) ($transition['from_status_id'] ?? 0);
                $to = (int) ($transition['to_status_id'] ?? 0);

                if (! in_array($from, $enabledStatusIds, true) || ! in_array($to, $enabledStatusIds, true) || $from === $to) {
                    throw ValidationException::withMessages(['transitions' => 'Transitions must use two different enabled states.']);
                }

                $key = $from.'-'.$to;

                if (isset($seenTransitions[$key])) {
                    throw ValidationException::withMessages(['transitions' => 'Duplicate workflow transitions are not allowed.']);
                }

                $seenTransitions[$key] = true;

                return [
                    'from_status_id' => $from,
                    'to_status_id' => $to,
                    'label' => $transition['label'] ?: 'Move to '.(TicketStatus::find($to)?->name ?? 'state'),
                    'is_active' => true,
                    'manual_enabled' => (bool) ($transition['manual_enabled'] ?? true),
                    'trigger_actions' => array_values($transition['trigger_actions'] ?? []),
                    'requires_note' => (bool) ($transition['requires_note'] ?? false),
                    'requires_response' => (bool) ($transition['requires_response'] ?? false),
                    'requires_resolution' => (bool) ($transition['requires_resolution'] ?? false),
                    'requires_knowledge_update' => (bool) ($transition['requires_knowledge_update'] ?? false),
                    'sort_order' => (int) ($transition['sort_order'] ?? (($index + 1) * 10)),
                ];
            })
            ->values()
            ->all();

        return [
            'workflow' => [
                'name' => $data['name'],
                'slug' => $slug,
                'description' => $data['description'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? false),
                'is_default' => (bool) ($data['is_default'] ?? false),
                'sort_order' => (int) ($data['sort_order'] ?? 10),
            ],
            'states' => $states,
            'transitions' => $transitions,
        ];
    }

    private function syncWorkflowStates(TicketWorkflow $workflow, array $states): void
    {
        $statusIds = collect($states)->pluck('ticket_status_id')->all();

        $workflow->states()->whereNotIn('ticket_status_id', $statusIds)->delete();

        foreach ($states as $state) {
            $workflow->states()->updateOrCreate(
                ['ticket_status_id' => $state['ticket_status_id']],
                $state
            );
        }
    }

    private function syncWorkflowTransitions(TicketWorkflow $workflow, array $transitions): void
    {
        $workflow->transitions()->delete();

        foreach ($transitions as $transition) {
            $workflow->transitions()->create($transition);
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
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:ticket_types,slug,' . ($type?->id ?? 'NULL'),
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'is_deletable' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ]);

        $data['slug'] = $data['slug'] ?: Str::slug($data['name']);
        $this->ensureUniqueSlug(TicketType::class, $data['slug'], $type?->id, 'type');
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['is_deletable'] = $type?->is_system ? (bool) $type->is_deletable : (bool) ($data['is_deletable'] ?? true);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    private function validatedStatus(Request $request, ?TicketStatus $status = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:ticket_statuses,slug,' . ($status?->id ?? 'NULL'),
            'state' => 'required|string|in:open,waiting,resolved,closed',
            'is_default' => 'nullable|boolean',
            'is_closed' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ]);

        $data['slug'] = $data['slug'] ?: Str::slug($data['name']);
        $this->ensureUniqueSlug(TicketStatus::class, $data['slug'], $status?->id, 'status');
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        $data['is_closed'] = (bool) ($data['is_closed'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    private function validatedPriority(Request $request, ?TicketPriority $priority = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:ticket_priorities,slug,' . ($priority?->id ?? 'NULL'),
            'level' => 'required|integer|min:1|max:255',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ]);

        $data['slug'] = $data['slug'] ?: Str::slug($data['name']);
        $this->ensureUniqueSlug(TicketPriority::class, $data['slug'], $priority?->id, 'priority');
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    private function validatedRule(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'weight' => 'required|integer|min:0|max:100000',
            'is_active' => 'nullable|boolean',
            'stop_processing' => 'nullable|boolean',
            'conditions' => 'required|array|min:1',
            'conditions.*.field' => 'required|string|in:channel,subject,description,from_email,from_domain,email_tags,client_known,client_has_active_contract',
            'conditions.*.operator' => 'required|string|in:contains,equals,not_equals,starts_with,ends_with,regex,present',
            'conditions.*.value' => 'nullable|string|max:1000',
            'actions' => 'required|array|min:1',
            'actions.*.type' => 'required|string|in:set_ticket_type,set_queue,set_priority,set_sla,set_category,add_tag',
            'actions.*.value' => 'required|string|max:255',
        ]);

        $actions = collect($data['actions'])
            ->map(fn (array $action) => [
                'type' => $action['type'],
                'value' => $action['value'],
            ])
            ->values()
            ->all();

        $this->validateRuleActionTargets($actions);

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'weight' => $data['weight'],
            'is_active' => (bool) ($data['is_active'] ?? false),
            'stop_processing' => (bool) ($data['stop_processing'] ?? false),
            'conditions_json' => collect($data['conditions'])
                ->map(fn (array $condition) => [
                    'field' => $condition['field'],
                    'operator' => $condition['operator'],
                    'value' => $condition['value'] ?? '',
                ])
                ->values()
                ->all(),
            'actions_json' => $actions,
        ];
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
                default => false,
            };

            if (! $exists) {
                throw ValidationException::withMessages([
                    'actions' => 'Rule action target does not exist.',
                ]);
            }
        }
    }

    private function ensureSingleDefaultQueue(?TicketQueue $defaultQueue = null): void
    {
        // Only a submitted default should clear competing defaults; ordinary edits must not unset them.
        if ($defaultQueue && ! $defaultQueue->is_default) {
            return;
        }

        $defaultQueue ??= TicketQueue::where('is_default', true)->orderBy('sort_order')->first();

        if (! $defaultQueue) {
            return;
        }

        TicketQueue::where('id', '!=', $defaultQueue->id)->update(['is_default' => false]);
    }

    private function ensureSingleDefaultStatus(?TicketStatus $defaultStatus = null): void
    {
        if ($defaultStatus && ! $defaultStatus->is_default) {
            return;
        }

        $defaultStatus ??= TicketStatus::where('is_default', true)->orderBy('sort_order')->first();

        if (! $defaultStatus) {
            return;
        }

        TicketStatus::where('id', '!=', $defaultStatus->id)->update(['is_default' => false]);
    }

    private function ensureSingleDefaultPriority(?TicketPriority $defaultPriority = null): void
    {
        if ($defaultPriority && ! $defaultPriority->is_default) {
            return;
        }

        $defaultPriority ??= TicketPriority::where('is_default', true)->orderBy('level')->first();

        if (! $defaultPriority) {
            return;
        }

        TicketPriority::where('id', '!=', $defaultPriority->id)->update(['is_default' => false]);
    }

    private function ensureUniqueSlug(string $modelClass, string $slug, ?int $ignoreId, string $field): void
    {
        $exists = $modelClass::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                $field => 'Slug is already in use.',
            ]);
        }
    }

    private function ticketRuleReferences(string $actionType, int $id): bool
    {
        return TicketRule::query()
            ->get()
            ->contains(function (TicketRule $rule) use ($actionType, $id) {
                foreach ((array) $rule->actions_json as $action) {
                    if (($action['type'] ?? '') === $actionType && (int) ($action['value'] ?? 0) === $id) {
                        return true;
                    }
                }

                return false;
            });
    }
}
