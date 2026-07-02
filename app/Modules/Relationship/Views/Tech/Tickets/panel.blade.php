@if(($relationshipSyncLinks ?? collect())->isNotEmpty() || ($availableRelationships ?? collect())->isNotEmpty())
    <!-- Nexum relationship state is shown only when there is an active link or a real escalation target. -->
    <div class="card mb-3">
        <div class="card-header">Nexum relationship</div>
        <div class="card-body">
            @forelse($relationshipSyncLinks as $link)
                <div class="border rounded p-2 mb-2">
                    <div class="d-flex justify-content-between gap-2">
                        <div class="fw-semibold">{{ $link->relationship?->name }}</div>
                        <span class="badge text-bg-light border">{{ $link->sync_status }}</span>
                    </div>
                    <div class="small text-muted">Remote ID: {{ $link->remote_id ?: 'Pending' }}</div>
                    @if($link->last_error)
                        <div class="small text-danger mt-1">{{ $link->last_error }}</div>
                    @endif
                </div>
            @empty
                <p class="text-muted mb-2">This ticket is not linked to a remote Nexum ticket.</p>
            @endforelse

            @if(($availableRelationships ?? collect())->isNotEmpty())
                <form method="POST" action="{{ route('tech.tickets.relationships.escalate', $ticket) }}" class="d-flex gap-2">
                    @csrf
                    <select name="relationship_id" class="form-select form-select-sm" required>
                        @foreach($availableRelationships as $relationship)
                            <option value="{{ $relationship->id }}">{{ $relationship->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-sm btn-outline-primary">Escalate</button>
                </form>
            @endif
        </div>
    </div>
@endif
