<!-- Mailbox operation status stays owned by the Mail workspace while rendered in the right sidebar. -->
<div
    class="card mb-3"
    data-mailbox-operations-location="rightbar"
    data-mailbox-operations-state="{{ $remoteOperationsOpen ? 'expanded' : 'collapsed' }}">
    <div class="card-header p-0">
        <button
            type="button"
            id="mailbox-operations-rightbar-toggle"
            class="btn btn-sm btn-link text-body text-decoration-none text-start px-3 py-2 w-100 d-flex align-items-center justify-content-between gap-2"
            wire:click="toggleRemoteOperations"
            aria-expanded="{{ $remoteOperationsOpen ? 'true' : 'false' }}"
            aria-controls="mailbox-operations-rightbar-body">
            <span class="fw-semibold small">
                <i class="bi bi-cloud-arrow-up-down me-1" aria-hidden="true"></i>Mailbox operations
            </span>
            <span class="d-flex flex-wrap align-items-center justify-content-end gap-1">
                @if(($remoteOperationsDashboard['stats']['failed'] ?? 0) > 0)
                    <span class="badge text-bg-danger">{{ $remoteOperationsDashboard['stats']['failed'] }} failed</span>
                @endif
                @if(($remoteOperationsDashboard['stats']['pending'] ?? 0) > 0)
                    <span class="badge text-bg-light border">{{ $remoteOperationsDashboard['stats']['pending'] }} pending</span>
                @endif
                @if(($remoteOperationsDashboard['stats']['running'] ?? 0) > 0)
                    <span class="badge text-bg-info">{{ $remoteOperationsDashboard['stats']['running'] }} running</span>
                @endif
                @if(($remoteOperationsDashboard['stats']['recent'] ?? 0) > 0)
                    <span class="badge text-bg-success">{{ $remoteOperationsDashboard['stats']['recent'] }} recent</span>
                @endif
                <i class="bi {{ $remoteOperationsOpen ? 'bi-chevron-up' : 'bi-chevron-down' }} flex-shrink-0" aria-hidden="true"></i>
            </span>
        </button>
    </div>

    <div
        id="mailbox-operations-rightbar-body"
        role="region"
        aria-labelledby="mailbox-operations-rightbar-toggle"
        @if(! $remoteOperationsOpen) hidden @endif>
        <div class="card-body p-2">
            <div class="d-flex flex-wrap gap-1">
                <span class="badge text-bg-light border">{{ $remoteOperationsDashboard['stats']['pending'] ?? 0 }} pending</span>
                <span class="badge text-bg-info">{{ $remoteOperationsDashboard['stats']['running'] ?? 0 }} running</span>
                <span class="badge text-bg-danger">{{ $remoteOperationsDashboard['stats']['failed'] ?? 0 }} failed</span>
                <span class="badge text-bg-success">{{ $remoteOperationsDashboard['stats']['recent'] ?? 0 }} recent success</span>
            </div>

            <div class="mt-2">
                @forelse($remoteOperationsDashboard['items'] as $operation)
                    <div class="border-top py-2">
                        <div class="small fw-semibold text-break">{{ ucfirst($operation['type']) }} / {{ $operation['subject'] }}</div>
                        <div class="small text-muted text-break">{{ $operation['account'] }} · {{ $operation['updated_at'] }}</div>
                        <span class="badge {{ $this->remoteOperationBadgeClass($operation['status']) }} mt-1">
                            {{ $this->remoteOperationLabel($operation['status']) }}
                        </span>
                        <div class="small text-muted text-break mt-1">{{ $operation['message'] }}</div>
                        <div class="small text-muted mt-1">
                            {{ $operation['provider_attempts'] }}/{{ $operation['max_attempts'] }} provider attempts
                            @if(($operation['mutation_attempts'] ?? 0) !== $operation['provider_attempts'])
                                · {{ $operation['mutation_attempts'] }} mutation attempts
                            @endif
                            @if($operation['failure_classification'])
                                · {{ ucfirst($operation['failure_classification']) }}
                            @endif
                        </div>
                        @if($operation['next_attempt_at'])
                            <div class="small text-primary">Safe retry due {{ $operation['next_attempt_at'] }}</div>
                        @endif
                        @if($operation['undo_reason'])
                            <div class="small {{ $operation['can_undo'] ? 'text-success' : 'text-muted' }} mt-1">
                                {{ $operation['undo_reason'] }}
                                @if($operation['can_undo'] && $operation['undo_expires_at'])
                                    Expires {{ $operation['undo_expires_at'] }}.
                                @endif
                                @if($operation['inverse_operation_id'])
                                    Inverse #{{ $operation['inverse_operation_id'] }} is {{ $operation['inverse_operation_status'] }}.
                                @endif
                            </div>
                        @endif
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            @if($operation['can_retry'])
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-primary flex-fill"
                                    wire:click="retryRemoteOperation({{ $operation['id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="retryRemoteOperation">
                                    <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Retry
                                </button>
                            @endif
                            @if($operation['can_cancel'])
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary flex-fill"
                                    wire:click="cancelRemoteOperation({{ $operation['id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="cancelRemoteOperation">
                                    <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Cancel
                                </button>
                            @endif
                            @if($operation['can_undo'])
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-success flex-fill"
                                    wire:click="undoRemoteOperation({{ $operation['id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="undoRemoteOperation">
                                    <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Undo
                                </button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="small text-muted border-top pt-2">No pending mailbox operations.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
