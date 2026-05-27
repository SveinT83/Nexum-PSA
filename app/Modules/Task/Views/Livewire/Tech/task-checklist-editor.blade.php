<div>
    <input type="hidden" name="checklist_text" value="{{ $this->checklistText() }}">

    <div class="d-grid gap-2">
        @foreach($items as $index => $item)
            <div class="input-group input-group-sm" wire:key="task-checklist-item-{{ $index }}">
                <input type="text" class="form-control" wire:model.live="items.{{ $index }}" placeholder="Checklist item">
                <button type="button" class="btn btn-outline-danger" wire:click="removeItem({{ $index }})" aria-label="Remove checklist item">
                    <i class="bi bi-dash-lg" aria-hidden="true"></i>
                </button>
            </div>
        @endforeach
    </div>

    <button type="button" class="btn btn-sm btn-outline-primary mt-2" wire:click="addItem">
        <i class="bi bi-plus-lg" aria-hidden="true"></i>
        Add item
    </button>
</div>
