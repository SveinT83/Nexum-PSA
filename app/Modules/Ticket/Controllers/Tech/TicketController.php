<?php

namespace App\Modules\Ticket\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Ticket\Actions\AddTicketMessage;
use App\Modules\Ticket\Actions\ChangeTicketStatus;
use App\Modules\Ticket\Actions\CloseTicket;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\MarkTicketRead;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Actions\UpdateTicketFields;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketCategory;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Queries\TicketIndexQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class TicketController extends Controller
{
    public function index(Request $request, TicketIndexQuery $query, EnsureTicketDefaults $defaults): View
    {
        $defaults->handle();

        $filters = $request->only(['q', 'status_id', 'queue_id', 'ownership', 'client_id', 'sort']);

        return view('ticket::Tech.Tickets.index', [
            'tickets' => $query->paginate($filters),
            'queues' => TicketQueue::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'statuses' => TicketStatus::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'clients' => Client::where('active', true)->orderBy('name')->get(['id', 'name', 'client_number']),
            'filters' => $filters,
            'stats' => [
                'open' => Ticket::whereHas('status', fn ($query) => $query->where('is_closed', false))->count(),
                'mine' => Ticket::where('owner_id', $request->user()?->id)->count(),
                'unread' => Ticket::where('is_unread', true)->count(),
            ],
        ]);
    }

    public function create(Request $request, EnsureTicketDefaults $defaults): View
    {
        $defaults->handle();

        $selectedClientId = $request->integer('client_id') ?: null;
        $selectedClient = $selectedClientId
            ? Client::where('active', true)->find($selectedClientId)
            : null;

        return view('ticket::Tech.Tickets.create', [
            'queues' => TicketQueue::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'statuses' => TicketStatus::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'priorities' => TicketPriority::where('is_active', true)->orderBy('level')->get(),
            'categories' => TicketCategory::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'clients' => Client::where('active', true)->orderBy('name')->get(['id', 'name', 'client_number']),
            'contacts' => $selectedClient
                ? ClientUser::whereHas('site', fn ($query) => $query->where('client_id', $selectedClient->id))
                    ->where('active', true)
                    ->orderByDesc('is_default_for_client')
                    ->orderBy('name')
                    ->get(['id', 'name', 'email', 'phone'])
                : collect(),
            'technicians' => $this->technicians(),
            'selectedClient' => $selectedClient,
            'openClientTickets' => $selectedClient
                ? Ticket::with(['status', 'priority'])
                    ->where('client_id', $selectedClient->id)
                    ->whereHas('status', fn ($query) => $query->where('is_closed', false))
                    ->latest('updated_at')
                    ->limit(8)
                    ->get()
                : collect(),
        ]);
    }

    public function store(Request $request, StoreTicket $storeTicket): RedirectResponse
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string',
            'queue_id' => 'nullable|exists:ticket_queues,id',
            'status_id' => 'nullable|exists:ticket_statuses,id',
            'priority_id' => 'nullable|exists:ticket_priorities,id',
            'category_id' => 'nullable|exists:ticket_categories,id',
            'client_id' => 'nullable|exists:clients,id',
            'contact_id' => 'nullable|exists:client_users,id',
            'owner_id' => ['nullable', Rule::exists((new User())->getTable(), 'id')],
            'type' => 'nullable|string|max:50',
            'impact' => 'nullable|integer|min:1|max:5',
            'urgency' => 'nullable|integer|min:1|max:5',
        ]);

        if (! empty($data['contact_id']) && empty($data['client_id'])) {
            return back()
                ->withErrors(['client_id' => 'Select a client before selecting a contact.'])
                ->withInput();
        }

        if (! empty($data['contact_id']) && ! empty($data['client_id'])) {
            $contactBelongsToClient = ClientUser::whereKey($data['contact_id'])
                ->whereHas('site', fn ($query) => $query->where('client_id', $data['client_id']))
                ->exists();

            if (! $contactBelongsToClient) {
                return back()
                    ->withErrors(['contact_id' => 'The selected contact does not belong to the selected client.'])
                    ->withInput();
            }
        }

        $data['channel'] = 'manual';

        $ticket = $storeTicket->handle($data, $request->user());

        return redirect()->route('tech.tickets.show', $ticket)
            ->with('success', 'Ticket ' . $ticket->ticket_key . ' created.');
    }

    public function show(Ticket $ticket): View
    {
        app(EnsureTicketDefaults::class)->handle();

        $ticket->load(['queue', 'status', 'priority', 'category', 'client', 'contact', 'owner', 'messages', 'events']);
        $messageIds = $ticket->messages->pluck('id')->all();

        return view('ticket::Tech.Tickets.show', [
            'ticket' => $ticket,
            'queues' => TicketQueue::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'statuses' => TicketStatus::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'priorities' => TicketPriority::where('is_active', true)->orderBy('level')->get(),
            'categories' => TicketCategory::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'technicians' => $this->technicians(),
            'emailLogsByMessageId' => EmailLog::query()
                ->where('direction', 'outbound')
                ->where('scope', 'tickets')
                ->whereIn('context_json->ticket_message_id', $messageIds)
                ->latest()
                ->get()
                ->groupBy(fn (EmailLog $log) => (int) ($log->context_json['ticket_message_id'] ?? 0)),
        ]);
    }

    public function addMessage(Request $request, Ticket $ticket, AddTicketMessage $addTicketMessage): RedirectResponse
    {
        $data = $request->validate([
            'body' => 'required|string',
            'type' => 'required|string|in:internal_note,customer_reply',
            'visibility' => 'required|string|in:internal,public',
        ]);

        $ticket->loadMissing('contact');

        if ($data['type'] === 'customer_reply' && empty($ticket->contact?->email)) {
            return back()
                ->withErrors(['type' => 'A customer reply requires a ticket contact with an email address.'])
                ->withInput();
        }

        $data['visibility'] = $data['type'] === 'internal_note' ? 'internal' : 'public';

        $addTicketMessage->handle($ticket, $data, $request->user());

        return redirect()->route('tech.tickets.show', $ticket)
            ->with('success', 'Message added.');
    }

    public function update(Request $request, Ticket $ticket, UpdateTicketFields $updateTicketFields, ChangeTicketStatus $changeTicketStatus): RedirectResponse
    {
        $data = $request->validate([
            'queue_id' => 'required|exists:ticket_queues,id',
            'status_id' => 'required|exists:ticket_statuses,id',
            'priority_id' => 'required|exists:ticket_priorities,id',
            'category_id' => 'nullable|exists:ticket_categories,id',
            'owner_id' => ['nullable', Rule::exists((new User())->getTable(), 'id')],
        ]);

        $queue = TicketQueue::where('is_active', true)->findOrFail($data['queue_id']);
        $status = TicketStatus::where('is_active', true)->findOrFail($data['status_id']);
        $priority = TicketPriority::where('is_active', true)->findOrFail($data['priority_id']);
        $categoryId = empty($data['category_id'])
            ? null
            : TicketCategory::where('is_active', true)->findOrFail($data['category_id'])->id;

        $updateTicketFields->handle($ticket, [
            'queue_id' => $queue->id,
            'priority_id' => $priority->id,
            'category_id' => $categoryId,
            'owner_id' => $data['owner_id'] ?? null,
        ], $request->user());

        $changeTicketStatus->handle($ticket->refresh(), $status, $request->user());

        return redirect()->route('tech.tickets.show', $ticket->refresh())
            ->with('success', 'Ticket updated.');
    }

    public function close(Request $request, Ticket $ticket, CloseTicket $closeTicket): RedirectResponse
    {
        $closeTicket->handle($ticket, $request->user());

        return redirect()->route('tech.tickets.show', $ticket->refresh())
            ->with('success', 'Ticket closed.');
    }

    public function markRead(Request $request, Ticket $ticket, MarkTicketRead $markTicketRead): RedirectResponse
    {
        $markTicketRead->handle($ticket, $request->user());

        return redirect()->route('tech.tickets.show', $ticket)
            ->with('success', 'Ticket marked as read.');
    }

    private function technicians()
    {
        $requestedRoles = ['ticket.admin', 'ticket.agent', 'ticket.view', 'tech.admin', 'Superuser', 'Admin', 'Tech'];
        $existingRoles = Role::whereIn('name', $requestedRoles)->pluck('name')->all();

        $query = ! empty($existingRoles)
            ? User::role($existingRoles)
            : User::query();

        $technicians = $query
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $currentUser = auth()->user();

        if ($currentUser?->isActive() && ! $technicians->contains('id', $currentUser->id)) {
            $technicians->push($currentUser);
        }

        return $technicians->sortBy('name')->values();
    }
}
