<div>
    <!-- Notes header and honest persistence state -->
    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
        <label for="client_notes" class="form-label small text-body-secondary mb-0">Notes</label>

        <span class="small text-body-secondary" aria-live="polite">
            <span wire:dirty wire:loading.remove wire:target="notes">Saving...</span>
            <span wire:loading wire:target="notes">Saving...</span>
            @if($saveState === 'saved')
                <span
                    class="text-success"
                    wire:dirty.remove
                    wire:loading.remove
                    wire:target="notes"
                >Saved</span>
            @endif
        </span>
    </div>

    <!-- Authorized inline editor or read-only Notes content -->
    @if($canEdit)
        <textarea
            id="client_notes"
            class="form-control form-control-sm @error('notes') is-invalid @enderror"
            rows="4"
            wire:model.live.debounce.750ms="notes"
            aria-describedby="client_notes_feedback"
        ></textarea>

        <div id="client_notes_feedback" class="invalid-feedback @error('notes') d-block @enderror" aria-live="assertive">
            @error('notes') {{ $message }} @enderror
        </div>
    @else
        <div id="client_notes" class="small" style="white-space: pre-wrap">{{ filled($notes) ? $notes : 'No notes registered.' }}</div>
    @endif
</div>
