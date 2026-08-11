<?php

namespace App\Modules\Clients\Livewire\Tech;

use App\Models\Clients\Client;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class ClientNotesAutosave extends Component
{
    #[Locked]
    public int $clientId;

    public string $notes = '';

    public bool $canEdit = false;

    public string $saveState = 'idle';

    /**
     * Load the current Notes value and expose only the authorized editing surface.
     */
    public function mount(Client $client): void
    {
        $this->clientId = (int) $client->getKey();
        $this->notes = (string) ($client->notes ?? '');
        $this->canEdit = auth()->user()?->can('client.update') === true;
    }

    /**
     * Clear stale confirmation or failure state as soon as Notes changes.
     */
    public function updatingNotes(): void
    {
        $this->saveState = 'idle';
        $this->resetErrorBag('notes');
    }

    /**
     * Persist the debounced Notes update after Livewire receives it.
     */
    public function updatedNotes(): void
    {
        $this->saveNotes();
    }

    public function saveNotes(): void
    {
        abort_unless(auth()->user()?->can('client.update') === true, 403);

        $this->resetErrorBag('notes');

        try {
            $validated = $this->validate([
                'notes' => ['nullable', 'string'],
            ]);

            $client = Client::query()->findOrFail($this->clientId);
            $client->forceFill([
                'notes' => filled($validated['notes']) ? $validated['notes'] : null,
            ])->save();

            $this->saveState = 'saved';
        } catch (Throwable $exception) {
            report($exception);

            $this->saveState = 'error';
            $this->addError('notes', 'Notes could not be saved. Your text is still here; try again.');
        }
    }

    public function render(): View
    {
        return view('clients::Livewire.Tech.client-notes-autosave');
    }
}
