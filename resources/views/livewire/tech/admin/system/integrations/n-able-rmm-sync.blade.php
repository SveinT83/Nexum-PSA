<div>
    {{--
        Interactive Documentation:
        This script listens for the 'triggerBatch' event from the Livewire component
        and calls the server-side processNextBatch method after a small delay.
        This enables asynchronous batch processing without blocking the PHP thread.
    --}}
    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('triggerBatch', () => {
                setTimeout(() => {
                    @this.processNextBatch();
                }, 100);
            });
        });
    </script>

    @if($showModal)
        {{-- Progress Modal: Displays real-time synchronization status to the user --}}
        <div class="modal fade show" style="display: block; background: rgba(0,0,0,0.5);" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            @if($syncType === 'clients_from') Syncing Clients from RMM
                            @elseif($syncType === 'clients_to') Syncing Clients to RMM
                            @elseif($syncType === 'sites_from') Syncing Sites from RMM
                            @elseif($syncType === 'sites_to') Syncing Sites to RMM
                            @elseif($syncType === 'assets_from')
                                @if($targetSiteId)
                                    Syncing Assets for Site from RMM
                                @else
                                    Syncing Assets for Client from RMM
                                @endif
                            @endif
                        </h5>
                        @if($isComplete)
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                        @endif
                    </div>
                    <div class="modal-body p-4">
                        @if($isProcessing)
                            <div class="text-center mb-4">
                                <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <div class="mt-3 fw-bold">Processing batch {{ $current }} of {{ $total }}...</div>
                                <div class="text-muted small">Please do not close this window</div>
                            </div>
                        @endif

                        <div class="progress mb-4" style="height: 30px; border-radius: 15px; box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);">
                            <div class="progress-bar progress-bar-striped progress-bar-animated @if($isComplete) bg-success @endif"
                                 role="progressbar"
                                 style="width: {{ $progress }}%; transition: width 0.5s ease;"
                                 aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100">
                                <span class="fw-bold">{{ $progress }}%</span>
                            </div>
                        </div>

                        <div class="row g-3 text-center mb-4">
                            <div class="col-3">
                                <div class="card bg-light border-0">
                                    <div class="card-body p-2">
                                        <div class="h3 mb-0 text-success">{{ $successCount }}</div>
                                        <div class="text-uppercase small fw-bold text-muted" style="font-size: 0.65rem;">Created</div>
                                    </div>
                                </div>
                            </div>
                            @if($syncType === 'clients_from' || $syncType === 'sites_from' || $syncType === 'assets_from')
                                <div class="col-3">
                                    <div class="card bg-light border-0">
                                        <div class="card-body p-2">
                                            <div class="h3 mb-0 text-info">{{ $updatedCount }}</div>
                                            <div class="text-uppercase small fw-bold text-muted" style="font-size: 0.65rem;">Updated</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-3">
                                    <div class="card bg-light border-0">
                                        <div class="card-body p-2">
                                            <div class="h3 mb-0 text-primary">{{ $linkedCount }}</div>
                                            <div class="text-uppercase small fw-bold text-muted" style="font-size: 0.65rem;">Linked</div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                            <div class="col-3">
                                <div class="card bg-light border-0">
                                    <div class="card-body p-2">
                                        <div class="h3 mb-0 text-danger">{{ $errorCount }}</div>
                                        <div class="text-uppercase small fw-bold text-muted" style="font-size: 0.65rem;">Errors</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Error Log: Displays detailed error messages if any occurred --}}
                        @if(count($errors) > 0)
                            <div class="alert alert-danger" style="max-height: 200px; overflow-y: auto;">
                                <ul class="mb-0 small">
                                    @foreach($errors as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        {{-- Completion Alert: Confirms the process finished --}}
                        @if($isComplete)
                            <div class="alert alert-success d-flex align-items-center mb-0">
                                <i class="bi bi-check-circle-fill me-2 h4 mb-0"></i>
                                <div>
                                    <div class="fw-bold">Synchronization completed!</div>
                                    <div class="small">The process finished successfully with {{ $successCount + $updatedCount + $linkedCount }} items processed.</div>
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer bg-light border-top-0">
                        @if($isComplete)
                            <button type="button" class="btn btn-primary px-4 fw-bold" wire:click="closeModal">Done</button>
                        @else
                            <button type="button" class="btn btn-secondary px-4" disabled>
                                <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                Syncing...
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
