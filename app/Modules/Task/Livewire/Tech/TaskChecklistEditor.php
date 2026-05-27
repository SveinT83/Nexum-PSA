<?php

namespace App\Modules\Task\Livewire\Tech;

use Livewire\Attributes\On;
use Livewire\Component;

class TaskChecklistEditor extends Component
{
    public array $items = [];

    public function mount(string|array|null $initialItems = null): void
    {
        $lines = is_array($initialItems)
            ? $initialItems
            : preg_split('/\r\n|\r|\n/', (string) $initialItems);

        $this->items = collect($lines)
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->values()
            ->all();

        if ($this->items === []) {
            $this->items = [''];
        }
    }

    public function addItem(): void
    {
        $this->items[] = '';
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);

        if ($this->items === []) {
            $this->items = [''];
        }
    }

    public function checklistText(): string
    {
        return collect($this->items)
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->implode("\n");
    }

    #[On('task-ai-checklist-suggested')]
    public function applyAiChecklist(array $items): void
    {
        $this->items = collect($items)
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->values()
            ->all();

        if ($this->items === []) {
            $this->items = [''];
        }
    }

    public function render()
    {
        return view('task::Livewire.Tech.task-checklist-editor');
    }
}
