<?php

namespace App\Modules\Ticket\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Actions\UpdateDefaultTicketEmailAccount;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TicketSettingsController extends Controller
{
    public function index(): View
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
            'queues' => TicketQueue::withCount('tickets')->orderBy('sort_order')->orderBy('name')->get(),
            'types' => TicketType::withCount('tickets')->orderBy('sort_order')->orderBy('name')->get(),
            'statuses' => TicketStatus::withCount('tickets')->orderBy('sort_order')->orderBy('name')->get(),
            'priorities' => TicketPriority::withCount('tickets')->orderBy('level')->orderBy('sort_order')->orderBy('name')->get(),
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

    public function rules(): View
    {
        return view('ticket::Admin.Settings.rules.index', [
            'rules' => TicketRule::query()->orderBy('weight')->orderBy('id')->get(),
            'types' => TicketType::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'queues' => TicketQueue::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'priorities' => TicketPriority::where('is_active', true)->orderBy('level')->get(),
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
        return view()->exists('ticket::Admin.Settings.workflows.index')
            ? view('ticket::Admin.Settings.workflows.index')
            : view('ticket::Admin.Settings.index');
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
            'actions.*.type' => 'required|string|in:set_ticket_type,set_queue,set_priority,set_category,add_tag',
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
