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
        $editor = app(\App\Modules\Ticket\Services\TicketAssignmentRuleEditor::class);
        $editor->create($editor->data($request), $request->user());
        return back()->with('success', 'Assignment rule created.');
    }

    public function destroy(Request $request, TicketAssignmentRule $rule): RedirectResponse
    {
        app(\App\Modules\Ticket\Services\TicketAssignmentRuleEditor::class)->delete($rule, $request->user());
        return back()->with('success', 'Assignment rule deleted.');
    }
}
