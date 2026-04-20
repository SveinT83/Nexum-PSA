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
                            @endif
                        </h5>
                        {{-- Close button is only enabled when processing is complete --}}
                        @if($isComplete)
                            <button type="button" class="btn-close" wire:click="closeModal"></button>
                        @endif
                    </div>
                    <div class="modal-body">
                        {{-- Processing Indicator: Shows current batch progress --}}
                        @if($isProcessing)
                            <div class="text-center mb-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <div class="mt-2">Processing batch {{ $current }} of {{ $total }}...</div>
                            </div>
                        @endif

                        {{-- Progress Bar: Visual representation of completion percentage --}}
                        <div class="progress mb-3" style="height: 25px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                                 style="width: {{ $progress }}%;" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100">
                                {{ $progress }}%
                            </div>
                        </div>

                        {{-- Statistics Summary: Breakdown of synchronization results --}}
                        <div class="row text-center mb-3">
                            <div class="col">
                                <div class="h4 mb-0 text-success">{{ $successCount }}</div>
                                <div class="small text-muted">Created</div>
                            </div>
                            @if($syncType === 'clients_from' || $syncType === 'sites_from')
                                <div class="col">
                                    <div class="h4 mb-0 text-info">{{ $updatedCount }}</div>
                                    <div class="small text-muted">Updated</div>
                                </div>
                                <div class="col">
                                    <div class="h4 mb-0 text-primary">{{ $linkedCount }}</div>
                                    <div class="small text-muted">Linked</div>
                                </div>
                            @endif
                            <div class="col">
                                <div class="h4 mb-0 text-danger">{{ $errorCount }}</div>
                                <div class="small text-muted">Errors</div>
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
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i> Synchronization completed successfully.
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        @if($isComplete)
                            <button type="button" class="btn btn-primary" wire:click="closeModal">Close</button>
                        @else
                            <button type="button" class="btn btn-secondary" disabled>Processing...</button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
