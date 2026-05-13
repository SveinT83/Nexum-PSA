<?php

namespace App\Modules\Ticket\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\TicketAssignmentRule;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AssignmentRuleAdminController extends Controller
{
    public function index(): View
    {
        return view('ticket::Admin.AssignmentRules.index', [
            'rules' => TicketAssignmentRule::query()->orderBy('weight')->orderBy('id')->get(),
            'users' => User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name', 'email']),
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'contacts' => ClientUser::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'email']),
            'queues' => TicketQueue::query()->where('is_active', true)->orderBy('name')->get(),
            'categories' => Category::forTickets()->active()->orderBy('name')->get(),
            'tags' => Tag::where('active', true)->orderBy('name')->get(),
            'priorities' => TicketPriority::query()->where('is_active', true)->orderBy('level')->get(),
            'types' => TicketType::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'weight' => 'required|integer|min:0|max:100000',
            'is_active' => 'nullable|boolean',
            'stop_processing' => 'nullable|boolean',
            'conditions' => 'required|array|min:1',
            'conditions.*.field' => 'required|string|in:client_id,contact_id,queue_id,category_id,tag_ids,priority_id,ticket_type_id,channel',
            'conditions.*.operator' => 'required|string|in:equals,not_equals,contains,present',
            'conditions.*.value' => 'nullable|string|max:255',
            'action_value' => ['required', Rule::exists((new User())->getTable(), 'id')],
        ]);

        $conditions = collect($data['conditions'])
            ->filter(fn (array $condition) => ($condition['operator'] ?? 'equals') === 'present' || trim((string) ($condition['value'] ?? '')) !== '')
            ->map(fn (array $condition) => [
                'field' => $condition['field'],
                'operator' => $condition['operator'],
                'value' => $condition['value'] ?? '',
            ])
            ->values();

        if ($conditions->isEmpty()) {
            return back()
                ->withErrors(['conditions' => 'Add at least one assignment condition.'])
                ->withInput();
        }

        TicketAssignmentRule::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'weight' => $data['weight'],
            'is_active' => (bool) ($data['is_active'] ?? false),
            'stop_processing' => (bool) ($data['stop_processing'] ?? true),
            'conditions_json' => $conditions->all(),
            'action_type' => 'assign_user',
            'action_value' => (string) $data['action_value'],
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'Assignment rule created.');
    }

    public function destroy(TicketAssignmentRule $rule): RedirectResponse
    {
        $rule->delete();

        return back()->with('success', 'Assignment rule deleted.');
    }
}
