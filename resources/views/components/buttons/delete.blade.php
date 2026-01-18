@props(['url', 'name' => 'this item', 'class' => null])

@php
    // Generer en unik ID for denne spesifikke modalen (viktig i tabeller)
    $modalId = 'deleteModal_' . md5($url);
@endphp


<!-- Trigger-knapp -->
<button type="button"
        class="{{ $class ?? 'btn btn-sm btn-outline-danger bi bi-trash' }}"
        data-bs-toggle="modal"
        data-bs-target="#{{ $modalId }}">
    {{ $slot->isEmpty() ? 'Delete' : $slot }}
</button>

<!-- Modal-struktur -->
<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="{{ $url }}" method="POST">
            @csrf
            @method('DELETE')

            <div class="modal-content text-start"> {{-- text-start sikrer venstrestilt tekst i modaler --}}
                <div class="modal-header">
                    <h5 class="modal-title text-danger">Confirm Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-dark">
                    Are you sure you want to delete <strong>{{ $name }}</strong>?
                    <br>
                    <span class="small text-muted">This action cannot be undone.</span>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-danger">Permanently Delete</button>
                </div>
            </div>
        </form>
    </div>
</div>

