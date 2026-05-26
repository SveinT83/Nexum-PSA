<!-- ======================================================================
     DOCUMENTATION CARD
     - Provides a compact help surface for Storage item terminology
     ====================================================================== -->
<div class="accordion mb-3" id="storageItemDocumentationAccordion">
    <div class="accordion-item">
        <h2 class="accordion-header" id="storageItemDocumentationHeader">
            <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#storageItemDocumentationCollapse" aria-expanded="false" aria-controls="storageItemDocumentationCollapse">
                Documentation
            </button>
        </h2>
        <div id="storageItemDocumentationCollapse" class="accordion-collapse collapse" aria-labelledby="storageItemDocumentationHeader" data-bs-parent="#storageItemDocumentationAccordion">
            <div class="accordion-body">
                <p class="small text-muted">
                    Storage item field guide for stock levels, pricing, suppliers, lead time, MOQ, and VAT.
                </p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#storageItemDocModal">
                        <i class="bi bi-book me-1"></i> View Full Documentation
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================
     DOCUMENTATION MODAL
     - Renders the Storage module documentation file within the UI
     ====================================================================== -->
<div class="modal fade" id="storageItemDocModal" tabindex="-1" aria-labelledby="storageItemDocModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="storageItemDocModalLabel">Storage Item Documentation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="markdown-body">
                    @php
                        // Keep the in-page preview and `/storage/docs` route on
                        // the same module-local source file.
                        $docPath = app_path('Modules/Storage/Views/Tech/Storage/storage.md');
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
                <a href="{{ route('tech.storage.docs') }}" class="btn btn-primary" target="_blank">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Open in New Tab
                </a>
            </div>
        </div>
    </div>
</div>
