<?php

namespace App\Modules\Task\Livewire\Tech;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Task\Models\Task;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Queries\TicketTimeRateOptions;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

class TaskFormContext extends Component
{
    public ?int $clientId = null;
    public ?string $clientSearch = null;
    public ?int $siteId = null;
    public ?string $siteSearch = null;
    public ?int $ticketId = null;
    public ?string $ticketSearch = null;
    public ?string $ticketRateKey = null;
    public ?int $estimatedMinutes = null;
    public ?int $parentId = null;
    public ?int $currentTaskId = null;
    public bool $clientPickerOpen = false;
    public bool $sitePickerOpen = false;
    public bool $ticketPickerOpen = false;
    public bool $isEdit = false;

    public function mount($task = null, $ownerContext = null, array $prefill = []): void
    {
        $this->isEdit = (bool) ($task?->exists);
        $this->currentTaskId = $task?->id;
        $this->parentId = old('parent_id', $task?->parent_id);
        $this->clientId = old('client_id', $task?->client_id ?? ($prefill['client_id'] ?? null));
        $this->siteId = old('site_id', $task?->site_id ?? ($prefill['site_id'] ?? null));
        $this->estimatedMinutes = old('estimated_minutes', $task?->estimated_minutes ?? ($prefill['estimated_minutes'] ?? null));
        $this->ticketRateKey = old('ticket_rate_key', $task?->metadata['ticket_rate_key'] ?? ($prefill['ticket_rate_key'] ?? null));

        if ($ownerContext instanceof Ticket) {
            $this->selectTicket($ownerContext->id);
        } elseif ($task?->owner instanceof Ticket) {
            $this->selectTicket($task->owner->id);
        } else {
            $this->syncSelectedLabels();
        }
    }

    public function updatedClientSearch(): void
    {
        $this->clientPickerOpen = true;
        $this->clientId = null;
        $this->ticketId = null;
        $this->ticketRateKey = null;
    }

    public function updatedSiteSearch(): void
    {
        $this->sitePickerOpen = true;
        $this->siteId = null;
        $this->ticketId = null;
        $this->ticketRateKey = null;
    }

    public function updatedTicketSearch(): void
    {
        $this->ticketPickerOpen = true;
        $this->ticketId = null;
        $this->ticketRateKey = null;
    }

    public function openClientPicker(): void
    {
        $this->clientPickerOpen = true;
    }

    public function openSitePicker(): void
    {
        $this->sitePickerOpen = true;
    }

    public function openTicketPicker(): void
    {
        $this->ticketPickerOpen = true;
    }

    public function selectClient(int $clientId): void
    {
        $client = Client::query()->find($clientId);

        if (! $client) {
            return;
        }

        $this->clientId = $client->id;
        $this->clientSearch = $client->name;
        $this->siteId = null;
        $this->siteSearch = null;
        $this->ticketId = null;
        $this->ticketSearch = null;
        $this->ticketRateKey = null;
        $this->parentId = null;
        $this->clientPickerOpen = false;
    }

    public function selectSite(int $siteId): void
    {
        $site = ClientSite::query()->with('client')->find($siteId);

        if (! $site) {
            return;
        }

        $this->siteId = $site->id;
        $this->siteSearch = $site->name;
        $this->clientId = $site->client_id;
        $this->clientSearch = $site->client?->name;
        $this->ticketId = null;
        $this->ticketSearch = null;
        $this->ticketRateKey = null;
        $this->parentId = null;
        $this->sitePickerOpen = false;
    }

    public function selectTicket(int $ticketId): void
    {
        $ticket = Ticket::query()->with(['client', 'site'])->find($ticketId);

        if (! $ticket) {
            return;
        }

        $this->ticketId = $ticket->id;
        $this->ticketSearch = trim($ticket->ticket_key.' '.$ticket->subject);
        $this->clientId = $ticket->client_id;
        $this->clientSearch = $ticket->client?->name;
        $this->siteId = $ticket->site_id;
        $this->siteSearch = $ticket->site?->name;

        if (! $this->ticketRateOptions()->firstWhere('key', $this->ticketRateKey)) {
            $this->ticketRateKey = null;
        }

        $this->parentId = null;
        $this->ticketPickerOpen = false;
    }

    public function clearTicket(): void
    {
        $this->ticketId = null;
        $this->ticketSearch = null;
        $this->ticketRateKey = null;
        $this->parentId = null;
    }

    #[On('task-ai-context-suggested')]
    public function applyAiContext(array $suggestions): void
    {
        if (! empty($suggestions['ticket_id'])) {
            $this->selectTicket((int) $suggestions['ticket_id']);
        }

        if (! empty($suggestions['estimated_minutes'])) {
            $this->estimatedMinutes = (int) $suggestions['estimated_minutes'];
        }

        if (! empty($suggestions['ticket_rate_key'])) {
            $this->ticketRateKey = (string) $suggestions['ticket_rate_key'];
        }

        if (! empty($suggestions['parent_id'])) {
            $this->parentId = (int) $suggestions['parent_id'];
        }
    }

    public function clientResults(): Collection
    {
        if (! $this->clientPickerOpen) {
            return collect();
        }

        return Client::query()
            ->when($this->clientSearch, fn ($query) => $query->where('name', 'like', '%'.$this->clientSearch.'%'))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name']);
    }

    public function siteResults(): Collection
    {
        if (! $this->sitePickerOpen) {
            return collect();
        }

        return ClientSite::query()
            ->with('client:id,name')
            ->when($this->clientId, fn ($query) => $query->where('client_id', $this->clientId))
            ->when($this->siteSearch, fn ($query) => $query->where('name', 'like', '%'.$this->siteSearch.'%'))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'client_id', 'name']);
    }

    public function ticketResults(): Collection
    {
        if (! $this->ticketPickerOpen) {
            return collect();
        }

        return Ticket::query()
            ->with(['client:id,name', 'site:id,name'])
            ->when($this->clientId, fn ($query) => $query->where('client_id', $this->clientId))
            ->when($this->siteId, fn ($query) => $query->where('site_id', $this->siteId))
            ->when($this->ticketSearch, function ($query) {
                $query->where(function ($nested) {
                    $nested->where('ticket_key', 'like', '%'.$this->ticketSearch.'%')
                        ->orWhere('subject', 'like', '%'.$this->ticketSearch.'%');
                });
            })
            ->latest('updated_at')
            ->limit(8)
            ->get(['id', 'ticket_key', 'subject', 'client_id', 'site_id', 'updated_at']);
    }

    public function parentOptions(): Collection
    {
        return Task::query()
            ->with(['status:id,name', 'owner'])
            ->when($this->currentTaskId, fn ($query) => $query->whereKeyNot($this->currentTaskId))
            ->when($this->ticketId, function ($query) {
                $query->where('owner_type', (new Ticket())->getMorphClass())
                    ->where('owner_id', $this->ticketId);
            })
            ->when(! $this->ticketId && $this->siteId, fn ($query) => $query->where('site_id', $this->siteId))
            ->when(! $this->ticketId && ! $this->siteId && $this->clientId, fn ($query) => $query->where('client_id', $this->clientId))
            ->whereNull('completed_at')
            ->latest('updated_at')
            ->limit(50)
            ->get(['id', 'title', 'status_id', 'owner_type', 'owner_id', 'client_id', 'site_id', 'updated_at']);
    }

    public function ticketRateOptions(): Collection
    {
        if (! $this->ticketId) {
            return collect();
        }

        $ticket = Ticket::query()->find($this->ticketId);

        return $ticket ? app(TicketTimeRateOptions::class)->forTicket($ticket) : collect();
    }

    public function render()
    {
        return view('task::Livewire.Tech.task-form-context', [
            'clientResults' => $this->clientResults(),
            'siteResults' => $this->siteResults(),
            'ticketResults' => $this->ticketResults(),
            'ticketRateOptions' => $this->ticketRateOptions(),
            'parentOptions' => $this->parentOptions(),
            'ticketMorphClass' => (new Ticket())->getMorphClass(),
        ]);
    }

    private function syncSelectedLabels(): void
    {
        $this->clientSearch = $this->clientId ? Client::query()->whereKey($this->clientId)->value('name') : null;
        $this->siteSearch = $this->siteId ? ClientSite::query()->whereKey($this->siteId)->value('name') : null;
    }
}
