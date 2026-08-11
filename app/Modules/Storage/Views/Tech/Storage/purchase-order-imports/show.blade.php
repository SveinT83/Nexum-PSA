@extends('layouts.default_tech')

@section('title', 'Supplier Order Import #' . $import->id)

@section('sidebar')
    <x-nav.storage-menu />
@endsection

@section('pageHeader')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <h1 class="mb-0">Supplier Order Import #{{ $import->id }}</h1>
            <div class="small text-muted">{{ $safeSource['subject'] ?? $import->external_order_number ?? 'Supplier order review' }}</div>
        </div>
        <x-buttons.back :url="route('tech.storage.purchase-order-imports.index')" class="mb-0">Import Queue</x-buttons.back>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        @php
            $terminal = in_array($import->status, ['imported', 'duplicate', 'rejected', 'cancelled'], true);
            $manualMutationAllowed = ! $terminal
                && $import->status !== 'processing'
                && $import->purchase_order_id === null;
            $retryable = in_array($import->status, ['needs_attention', 'failed', 'retry_scheduled'], true);
            $statusClass = match($import->status) {
                'imported' => 'text-bg-success',
                'needs_attention', 'retry_scheduled' => 'text-bg-warning',
                'failed', 'rejected' => 'text-bg-danger',
                'processing' => 'text-bg-primary',
                'duplicate', 'cancelled' => 'text-bg-secondary',
                default => 'text-bg-light border',
            };
            $canRepairWithAi = ! in_array($import->status, ['duplicate', 'rejected', 'cancelled'], true)
                && $aiAvailability['available']
                && auth()->user()->can('storage.purchase_import_execute')
                && auth()->user()->can('storage.purchase_import_profile_manage');
            $from = (array) ($safeSource['from'] ?? []);
            $attachments = (array) ($safeSource['attachments'] ?? []);
            $totals = (array) data_get($normalizedDocument, 'totals', []);
            $candidatePurchaseOrderId = (int) data_get($import->reason_context, 'candidate_purchase_order_id', 0);
            $canOpenCandidatePurchaseOrder = $candidatePurchaseOrderId > 0
                && ! (bool) data_get($import->reason_context, 'candidate_deleted', false)
                && auth()->user()->can('storage.purchase_view');
            $candidatePurchaseOrderNumber = data_get($import->reason_context, 'candidate_po_number');
        @endphp

        {{-- Operational state and governed actions are kept together for review. --}}
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <h2 class="h6 mb-0">Import State</h2>
                    <span class="badge {{ $statusClass }}">{{ str($import->status)->replace('_', ' ')->title() }}</span>
                    <span class="badge text-bg-light border">{{ str($import->stage)->replace('_', ' ')->title() }}</span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    @if($import->purchaseOrder)
                        <a href="{{ route('tech.storage.purchase-orders.show', $import->purchaseOrder) }}" class="btn btn-sm btn-outline-primary">
                            Open {{ $import->purchaseOrder->po_number }}
                        </a>
                    @endif
                    @if($canOpenCandidatePurchaseOrder)
                        <a href="{{ route('tech.storage.purchase-orders.show', $candidatePurchaseOrderId) }}" class="btn btn-sm btn-outline-warning">
                            Review {{ $candidatePurchaseOrderNumber ?: 'matching purchase order' }}
                        </a>
                    @endif
                    @if($canOpenInbox)
                        <a href="{{ route('tech.inbox.show', $import->emailMessage) }}" class="btn btn-sm btn-outline-secondary">
                            Open Original in Inbox
                        </a>
                    @endif
                    @can('storage.purchase_import_execute')
                        @if($retryable)
                            <form method="POST" action="{{ route('tech.storage.purchase-order-imports.retry', $import) }}" class="mb-0">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-primary">Retry</button>
                            </form>
                        @endif
                        @if($canRepairWithAi)
                            <form method="POST" action="{{ route('tech.storage.purchase-order-imports.repair', $import) }}" class="mb-0"
                                  onsubmit="return confirm('Run governed AI repair for this import?');">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-primary">Repair with AI</button>
                            </form>
                        @endif
                        @if($manualMutationAllowed && auth()->user()->can('storage.purchase_manage'))
                            <form method="POST" action="{{ route('tech.storage.purchase-order-imports.finalize', $import) }}" class="mb-0"
                                  onsubmit="return confirm('Finalize this reviewed import as an ordered purchase order?');">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-success">Finalize Order</button>
                            </form>
                        @endif
                    @endcan
                </div>
            </div>
            <div class="card-body">
                @if($errors->any())
                    <div class="alert alert-danger py-2">
                        <ul class="mb-0">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="row g-3">
                    <div class="col-sm-6 col-xl-3">
                        <div class="small text-muted">Supplier</div>
                        <div class="fw-semibold">{{ $import->vendor?->name ?: 'Unresolved' }}</div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="small text-muted">External order</div>
                        <div class="fw-semibold">{{ $import->external_order_number ?: 'Not extracted' }}</div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="small text-muted">Extraction</div>
                        <div>{{ $import->extraction_method ?: 'Not completed' }}</div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="small text-muted">Attempts</div>
                        <div>{{ $import->attempt_count }} total</div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="small text-muted">Reason</div>
                        <div>{{ $import->reason_code ?: '-' }}</div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="small text-muted">Requested by</div>
                        <div>{{ $import->requester?->name ?: 'System' }}</div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="small text-muted">Created</div>
                        <div>{{ $import->created_at?->format('d.m.Y H:i:s') }}</div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="small text-muted">Next retry</div>
                        <div>{{ $import->next_retry_at?->format('d.m.Y H:i:s') ?: '-' }}</div>
                    </div>
                </div>

                @can('storage.purchase_import_execute')
                    @if($manualMutationAllowed)
                        <details class="mt-3">
                            <summary class="btn btn-sm btn-outline-danger">Reject Import</summary>
                            <form method="POST" action="{{ route('tech.storage.purchase-order-imports.reject', $import) }}"
                                  class="border rounded p-3 mt-2" style="max-width: 42rem;">
                                @csrf
                                <label for="reject_reason" class="form-label">Audit reason</label>
                                <textarea id="reject_reason" name="reason" rows="3" minlength="5" maxlength="1000"
                                          class="form-control form-control-sm @error('reason') is-invalid @enderror" required>{{ old('reason') }}</textarea>
                                @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <button type="submit" class="btn btn-sm btn-danger mt-2">Confirm Rejection</button>
                            </form>
                        </details>
                    @endif
                @endcan

                @can('storage.purchase_import_execute')
                    @if(auth()->user()->can('storage.purchase_import_profile_manage') && ! $aiAvailability['available'])
                        <div class="alert alert-light border small mt-3 mb-0">
                            <strong>AI repair unavailable:</strong> {{ $aiAvailability['reason'] }}
                        </div>
                    @endif
                @endcan

                @include('storage::Tech.Storage.purchase-order-imports._manual-correction')
            </div>
        </div>

        <div class="row g-3 mb-3">
            {{-- The immutable source snapshot is UI-safe and excludes raw headers and credentials. --}}
            <div class="col-12">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between gap-2">
                        <h2 class="h6 mb-0">Sanitized Source Snapshot</h2>
                        <span class="badge text-bg-light border">{{ $safeSource['source'] ?? $import->source_type }}</span>
                    </div>
                    <div class="card-body">
                        <dl class="row small mb-3">
                            <dt class="col-sm-3">Subject</dt>
                            <dd class="col-sm-9">{{ $safeSource['subject'] ?? '-' }}</dd>
                            <dt class="col-sm-3">From</dt>
                            <dd class="col-sm-9">{{ $from['name'] ?? '' }} {{ $from['email'] ?? '-' }}</dd>
                            <dt class="col-sm-3">Mailbox</dt>
                            <dd class="col-sm-9">{{ $safeSource['mailbox'] ?? '-' }}</dd>
                            <dt class="col-sm-3">Received</dt>
                            <dd class="col-sm-9">{{ $safeSource['received_at'] ?? '-' }}</dd>
                            <dt class="col-sm-3">Attachments</dt>
                            <dd class="col-sm-9">{{ count($attachments) }}</dd>
                        </dl>
                        @include('storage::Tech.Storage.purchase-order-imports._source-facts', [
                            'sourceImport' => $import,
                            'sourceDocument' => $normalizedDocument,
                        ])

                        @if(filled($safeSource['body_html'] ?? null))
                            <div class="border rounded bg-body p-3 overflow-auto" style="max-height: 28rem;">
                                {!! $safeSource['body_html'] !!}
                            </div>
                        @elseif(filled($safeSource['body_text'] ?? null))
                            <pre class="border rounded bg-body-tertiary p-3 small mb-0 text-wrap">{{ $safeSource['body_text'] }}</pre>
                        @else
                            <div class="text-muted">No retained message body is available.</div>
                        @endif

                        @if($attachments)
                            <div class="table-responsive mt-3">
                                <table class="table table-sm mb-0">
                                    <thead><tr><th>Attachment</th><th>Type</th><th class="text-end">Bytes</th><th>Checksum</th></tr></thead>
                                    <tbody>
                                    @foreach($attachments as $attachment)
                                        <tr>
                                            <td>{{ $attachment['name'] ?? 'Unnamed' }}</td>
                                            <td>{{ $attachment['mime_type'] ?? '-' }}</td>
                                            <td class="text-end">{{ number_format((int) ($attachment['size_bytes'] ?? 0)) }}</td>
                                            <td class="font-monospace small">{{ $attachment['checksum'] ?? '-' }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

        </div>

        {{-- Normalized line evidence supports one-by-one human resolution. --}}
        <div class="card mb-3" id="normalized-lines">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <div>
                    <h2 class="h6 mb-0">Normalized Lines and Item Mapping</h2>
                    <div class="small text-muted">{{ $import->lines->count() }} extracted lines</div>
                </div>
                <span class="badge text-bg-light border">{{ data_get($normalizedDocument, 'currency', '-') }}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Supplier SKU / description</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Unit price</th>
                        <th class="text-end">Line total</th>
                        <th>Mapping</th>
                        @can('storage.purchase_import_resolve')<th style="min-width: 19rem;">Resolve</th>@endcan
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($import->lines as $line)
                        <tr>
                            <td>{{ $line->position }}</td>
                            <td>
                                <div class="fw-semibold">{{ $line->supplier_sku ?: 'No supplier SKU' }}</div>
                                <div class="small text-muted">{{ $line->description ?: '-' }}</div>
                                @if($line->warnings)
                                    <div class="small text-warning">{{ implode(', ', (array) $line->warnings) }}</div>
                                @endif
                                @if($line->evidence)
                                    <details class="small mt-1">
                                        <summary>Evidence</summary>
                                        <pre class="bg-body-tertiary border rounded p-2 mt-1 mb-0 text-wrap">{{ json_encode($line->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @endif
                            </td>
                            <td class="text-end">{{ $line->quantity }}</td>
                            <td class="text-end">{{ $line->unit_price }}</td>
                            <td class="text-end">{{ $line->line_total }}</td>
                            <td>
                                <span class="badge text-bg-light border">{{ str($line->mapping_status)->replace('_', ' ')->title() }}</span>
                                @if($line->item)
                                    <div><a href="{{ route('tech.storage.items.show', $line->item) }}" class="small">{{ $line->item->sku }} - {{ $line->item->name }}</a></div>
                                @endif
                                @if($line->resolver)
                                    <div class="small text-muted">by {{ $line->resolver->name }}</div>
                                @endif
                            </td>
                            @can('storage.purchase_import_resolve')
                                <td>
                                    @if($manualMutationAllowed)
                                        <form method="POST" action="{{ route('tech.storage.purchase-order-imports.lines.map', [$import, $line]) }}" class="d-flex gap-1 mb-2">
                                            @csrf
                                            <select name="item_id" class="form-select form-select-sm" required>
                                                <option value="">Select existing Item</option>
                                                @foreach($mappableItems as $item)
                                                    <option value="{{ $item->id }}">{{ $item->sku }} - {{ $item->name }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-outline-primary">Map</button>
                                        </form>
                                        @if(auth()->user()->can('storage.purchase_import_execute') && auth()->user()->can('storage.purchase_manage'))
                                            <form method="POST" action="{{ route('tech.storage.purchase-order-imports.lines.create-item', [$import, $line]) }}" class="d-flex gap-1">
                                                @csrf
                                                <select name="mode" class="form-select form-select-sm" required>
                                                    <option value="create_review_item">Create review Item</option>
                                                    <option value="create_active_item">Create active Item</option>
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">Create</button>
                                            </form>
                                        @endif
                                    @else
                                        <span class="text-muted small">Import is processing, locked, or terminal</span>
                                    @endif
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ auth()->user()->can('storage.purchase_import_resolve') ? 7 : 6 }}" class="text-center text-muted py-4">
                                No normalized lines are stored for this import.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                    @if($import->lines->isNotEmpty())
                        <tfoot class="table-light">
                        <tr>
                            <th colspan="4">Extracted total</th>
                            <th class="text-end">{{ $totals['total'] ?? $totals['total_inc_tax'] ?? $totals['total_ex_tax'] ?? '-' }}</th>
                            <th colspan="{{ auth()->user()->can('storage.purchase_import_resolve') ? 2 : 1 }}"></th>
                        </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        <div class="row g-3">
            {{-- Pinned governance data explains the exact decision inputs used. --}}
            <div class="col-xl-5">
                <div class="card h-100">
                    <div class="card-header"><h2 class="h6 mb-0">Pinned Governance</h2></div>
                    <div class="card-body">
                        <dl class="row small mb-3">
                            <dt class="col-5">Profile</dt>
                            <dd class="col-7">{{ $import->profile?->name ?: '-' }}</dd>
                            <dt class="col-5">Profile version</dt>
                            <dd class="col-7">{{ $import->profileVersion?->version_number ?: '-' }}</dd>
                            <dt class="col-5">Global policy revision</dt>
                            <dd class="col-7">{{ $import->policyRevision?->revision_number ?: '-' }}</dd>
                            <dt class="col-5">Global policy checksum</dt>
                            <dd class="col-7 small font-monospace">{{ $import->policyRevision?->checksum ?: '-' }}</dd>
                            <dt class="col-5">Effective policy checksum</dt>
                            <dd class="col-7 small font-monospace">{{ $import->effective_policy_checksum ?: '-' }}</dd>
                            <dt class="col-5">Parser version</dt>
                            <dd class="col-7">{{ $import->parser_version ?: '-' }}</dd>
                            <dt class="col-5">AI execution</dt>
                            <dd class="col-7 font-monospace">{{ $import->ai_execution_uuid ?: '-' }}</dd>
                        </dl>
                        @if(is_array($import->effective_policy_snapshot))
                            <details>
                                <summary class="small fw-semibold">Effective policy snapshot</summary>
                                <pre class="small bg-body-tertiary border rounded p-2 mt-2 text-wrap">{{ json_encode($import->effective_policy_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        @endif
                        @if($import->validation_results)
                            <details>
                                <summary class="small fw-semibold">Validation results</summary>
                                <pre class="small bg-body-tertiary border rounded p-2 mt-2 text-wrap">{{ json_encode($import->validation_results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        @endif
                        @if($import->decision)
                            <details>
                                <summary class="small fw-semibold">Policy decision</summary>
                                <pre class="small bg-body-tertiary border rounded p-2 mt-2 text-wrap">{{ json_encode($import->decision, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        @endif
                        @if($import->reason_context)
                            <details>
                                <summary class="small fw-semibold">Reason context</summary>
                                <pre class="small bg-body-tertiary border rounded p-2 mt-2 text-wrap">{{ json_encode($import->reason_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Attempts are an append-only operational audit view. --}}
            <div class="col-xl-7">
                <div class="card h-100">
                    <div class="card-header"><h2 class="h6 mb-0">Processing Attempts</h2></div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th>#</th><th>Stage</th><th>Method</th><th>Status</th><th>Reason</th><th>Actor</th><th>Completed</th></tr></thead>
                            <tbody>
                            @forelse($import->attempts as $attempt)
                                <tr>
                                    <td>{{ $attempt->attempt_number }}</td>
                                    <td>{{ str($attempt->stage)->replace('_', ' ')->title() }}</td>
                                    <td>{{ $attempt->method ?: '-' }}</td>
                                    <td>{{ str($attempt->status)->replace('_', ' ')->title() }}</td>
                                    <td>{{ $attempt->reason_code ?: '-' }}</td>
                                    <td>{{ $attempt->actor?->name ?: $attempt->service_identity ?: 'System' }}</td>
                                    <td>{{ $attempt->completed_at?->format('d.m.Y H:i:s') ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-4">No attempts recorded.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        @include('storage::Tech.Storage.purchase-order-imports._repair-history')
    </div>
@endsection
