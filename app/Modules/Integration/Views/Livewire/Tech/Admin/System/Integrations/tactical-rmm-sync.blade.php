<div>
    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('checkTacticalProgress', () => {
                setTimeout(() => {
                    @this.checkProgress();
                }, 500);
            });

            Livewire.on('beginTacticalProcessing', () => {
                @this.processSync();
            });
        });
    </script>

    @if($showModal)
        <div class="modal fade show" style="display: block; background: rgba(0,0,0,0.5); z-index: 9999;" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            @if($syncType === 'clients_from' || $syncType === 'clients_and_sites' || $syncType === 'sites_from')
                                Syncing Clients and Sites from Tactical RMM
                            @elseif($syncType === 'assets_from')
                                @if($targetSiteId)
                                    Syncing Assets for Site from Tactical RMM
                                @else
                                    Syncing Assets from Tactical RMM
                                @endif
                            @endif
                        </h5>
                        @if($isComplete)
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                        @endif
                    </div>
                    <div class="modal-body p-4">
                        @if($isFetching)
                            <div class="text-center mb-4">
                                <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <div class="mt-3 fw-bold">Verifying connection and fetching data from Tactical RMM...</div>
                                <div class="text-muted small">This should only take a moment</div>
                            </div>
                        @endif

                        @if($isFetched && !$isProcessing && !$isComplete)
                            <div class="text-center mb-4">
                                <div class="alert alert-info border-0 shadow-sm mb-4">
                                    <h4 class="alert-heading"><i class="bi bi-info-circle me-2"></i>Data Found</h4>
                                    <p class="mb-0">Connection successful! We found the following items to process:</p>
                                </div>

                                <div class="row g-3 justify-content-center mb-4">
                                    @if($foundClientsCount > 0)
                                        <div class="col-sm-4">
                                            <div class="card border-0 bg-light">
                                                <div class="card-body">
                                                    <div class="h2 mb-0 text-primary">{{ $foundClientsCount }}</div>
                                                    <div class="text-muted small text-uppercase">Clients</div>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                    @if($foundSitesCount > 0)
                                        <div class="col-sm-4">
                                            <div class="card border-0 bg-light">
                                                <div class="card-body">
                                                    <div class="h2 mb-0 text-primary">{{ $foundSitesCount }}</div>
                                                    <div class="text-muted small text-uppercase">Sites</div>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                    @if($foundAssetsCount > 0)
                                        <div class="col-sm-4">
                                            <div class="card border-0 bg-light">
                                                <div class="card-body">
                                                    <div class="h2 mb-0 text-primary">{{ $foundAssetsCount }}</div>
                                                    <div class="text-muted small text-uppercase">Assets</div>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>

                                <button type="button" class="btn btn-success btn-lg px-5 shadow-sm fw-bold" wire:click="startSync">
                                    <i class="bi bi-cloud-download me-2"></i> Start Synchronization
                                </button>
                                <div class="mt-2 text-muted small">This will import new and update existing items in Nexum PSA.</div>
                            </div>
                        @endif

                        @if($isProcessing)
                            <div class="text-center mb-4">
                                <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                @if($total > 0)
                                    <div class="mt-3 fw-bold">Processing batch {{ $current }} of {{ $total }}...</div>
                                @else
                                    <div class="mt-3 fw-bold">Processing...</div>
                                    <div class="text-muted small mt-1">Please wait while we initialize the synchronization.</div>
                                @endif
                                @if($processingItemName)
                                    <div class="text-primary small mt-1 italic">Syncing: <strong>{{ $processingItemName }}</strong></div>
                                @endif
                                <div class="text-muted small">You can safely wait while we process the data</div>
                            </div>
                        @endif

                        @if($isProcessing || $isComplete)
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
                                <div class="col-3">
                                    <div class="card bg-light border-0">
                                        <div class="card-body p-2">
                                            <div class="h3 mb-0 text-danger">{{ $errorCount }}</div>
                                            <div class="text-uppercase small fw-bold text-muted" style="font-size: 0.65rem;">Errors</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if(count($errors) > 0)
                            <div class="alert alert-danger" style="max-height: 200px; overflow-y: auto;">
                                <ul class="mb-0 small">
                                    @foreach($errors as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

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
                        @elseif($isFetched && !$isProcessing)
                            <button type="button" class="btn btn-secondary px-4" wire:click="closeModal">Cancel</button>
                        @elseif($isFetching)
                            <button type="button" class="btn btn-secondary px-4" disabled>Fetching...</button>
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
