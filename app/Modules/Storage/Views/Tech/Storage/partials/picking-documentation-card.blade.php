<!-- ======================================================================
     PICKING DOCUMENTATION CARD
     - User-facing help for the ticket reservation picking queue
     ====================================================================== -->
<div class="accordion mb-3" id="storagePickingDocumentationAccordion">
    <div class="accordion-item">
        <h2 class="accordion-header" id="storagePickingDocumentationHeader">
            <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#storagePickingDocumentationCollapse" aria-expanded="false" aria-controls="storagePickingDocumentationCollapse">
                Documentation
            </button>
        </h2>
        <div id="storagePickingDocumentationCollapse" class="accordion-collapse collapse" aria-labelledby="storagePickingDocumentationHeader" data-bs-parent="#storagePickingDocumentationAccordion">
            <div class="accordion-body">
                <p class="small text-muted">
                    How to use the Picking List, understand Ready and Waiting items, and pick reserved ticket stock.
                </p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#storagePickingDocModal">
                        <i class="bi bi-book me-1"></i> View Full Documentation
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================
     PICKING DOCUMENTATION MODAL
     - Renders the user-facing picking documentation file within the UI
     ====================================================================== -->
<div class="modal fade" id="storagePickingDocModal" tabindex="-1" aria-labelledby="storagePickingDocModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="storagePickingDocModalLabel">Picking List Documentation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="markdown-body">
                    @php
                        // Keep the widget preview and `/storage/picking/docs`
                        // route on the same user-facing source file.
                        $docPath = app_path('Modules/Storage/Docs/knowledge/storage-picking-list.md');
                        if (file_exists($docPath)) {
                            if (class_exists('\Parsedown')) {
                                $parsedown = new \Parsedown();
                                echo $parsedown->text(file_get_contents($docPath));
                            } else {
                                echo '<div class="alert alert-warning small">Markdown parser not found. Displaying raw documentation:</div>';
                                echo '<pre style="white-space: pre-wrap; font-size: 0.85rem;">' . e(file_get_contents($docPath)) . '</pre>';
                            }
                        } else {
                            echo '<div class="alert alert-danger">Documentation file not found.</div>';
                        }
                    @endphp
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="{{ route('tech.storage.picking.docs') }}" class="btn btn-primary" target="_blank">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Open in New Tab
                </a>
            </div>
        </div>
    </div>
</div>
