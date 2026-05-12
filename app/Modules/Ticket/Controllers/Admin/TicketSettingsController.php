<?php

namespace App\Modules\Ticket\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Ticket\Actions\UpdateDefaultTicketEmailAccount;
use App\Modules\Ticket\Models\TicketQueue;
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
        if ($queue->tickets()->exists()) {
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
        if (! $type->is_deletable || $type->tickets()->exists()) {
            return back()->withErrors(['type' => 'Ticket type cannot be deleted while it is protected or in use.']);
        }

        $type->delete();

        return back()->with('success', 'Ticket type deleted.');
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
        return view()->exists('ticket::Admin.Settings.rules.index')
            ? view('ticket::Admin.Settings.rules.index')
            : view('ticket::Admin.Settings.index');
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

    private function ensureSingleDefaultQueue(?TicketQueue $defaultQueue = null): void
    {
        $defaultQueue ??= TicketQueue::where('is_default', true)->orderBy('sort_order')->first();

        if (! $defaultQueue) {
            return;
        }

        TicketQueue::where('id', '!=', $defaultQueue->id)->update(['is_default' => false]);
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
}
