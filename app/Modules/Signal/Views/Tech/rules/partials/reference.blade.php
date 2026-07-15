<div class="mt-3">
    <h2 class="h6">Rule Reference</h2>
    <p class="small text-muted">Rules run by lowest priority number first. Actions run from top to bottom.</p>
    <div class="small fw-semibold mb-1">Conditions</div>
    <div class="d-flex flex-wrap gap-1 mb-3">
        @foreach($definition::BUILDER_CONDITION_FIELDS as $label)
            <span class="badge text-bg-light border">{{ $label }}</span>
        @endforeach
    </div>
    <div class="small fw-semibold mb-1">Actions</div>
    <div class="vstack gap-2 small">
        @foreach($definition::ACTION_TYPES as $action)
            <div class="border rounded p-2 bg-body">
                <div class="fw-semibold">{{ $action['label'] }}</div>
                @if($action['required'])
                    <div class="text-muted">Requires: {{ implode(', ', $action['required']) }}</div>
                @endif
            </div>
        @endforeach
    </div>
    <hr>
    <p class="small text-muted mb-0">A failed action stops the remaining actions in that rule. Other matching rules still run. Retry is available from the Signal detail page.</p>
</div>
