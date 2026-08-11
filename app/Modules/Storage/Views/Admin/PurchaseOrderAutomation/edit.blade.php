@extends('layouts.default_tech')

@section('title', 'Supplier Order Automation')

@section('sidebar')
    <x-nav.admin-menu group="storage" />
@endsection

@section('pageHeader')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <h1 class="mb-0">Supplier Order Automation</h1>
            <div class="small text-muted">Choose how orders from supplier emails are prepared and registered. Changes are logged.</div>
        </div>
        <x-buttons.back :url="route('tech.admin.index')" class="mb-0">Admin</x-buttons.back>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        @if($storageAgents->isEmpty())
            <div class="alert alert-warning">
                <strong>AI assistance is not ready yet.</strong>
                Create or assign an active AI agent to Storage, then return here and select it.
            </div>
        @elseif($policy->ai_mode !== 'off' && ! $aiAvailability['available'])
            <div class="alert alert-light border">
                <strong>Current AI state:</strong> {{ $aiAvailability['reason'] }}
            </div>
        @endif

        {{-- The ordinary form exposes product decisions while Nexum owns technical execution controls. --}}
        <form method="POST" action="{{ route('tech.admin.settings.storage.purchase-order-automation.update') }}">
            @csrf
            @method('PUT')

            <div class="card mb-3">
                <div class="card-header"><h2 class="h6 mb-0">Order processing</h2></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="runtime_mode" class="form-label">Order handling</label>
                            <select id="runtime_mode" name="runtime_mode" class="form-select @error('runtime_mode') is-invalid @enderror" required>
                                @foreach([
                                    'off' => 'Off',
                                    'shadow' => 'Test only - creates nothing',
                                    'review' => 'Prepare for review (recommended)',
                                    'auto_deterministic' => 'Register automatically from supplier profiles only',
                                    'auto_verified_ai' => 'Register automatically from supplier profiles and AI',
                                ] as $value => $label)
                                    <option value="{{ $value }}"
                                            @selected(old('runtime_mode', $policy->runtime_mode) === $value)
                                            @disabled($value === 'auto_verified_ai' && $storageAgents->isEmpty())>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('runtime_mode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Review prepares the order for a person to check and finish. Automatic handling registers an ordered Purchase Order only after validation.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="default_warehouse_id" class="form-label">Default warehouse</label>
                            <select id="default_warehouse_id" name="default_warehouse_id" class="form-select">
                                <option value="">Require explicit destination</option>
                                @foreach($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" @selected((int) old('default_warehouse_id', $policy->default_warehouse_id) === (int) $warehouse->id)>
                                        {{ $warehouse->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="supplier_bootstrap_mode" class="form-label">Unknown suppliers</label>
                            <select id="supplier_bootstrap_mode" name="supplier_bootstrap_mode" class="form-select" required>
                                @foreach(['existing_only' => 'Stop for supplier matching (recommended)', 'review_candidate' => 'Create a supplier suggestion for review', 'create_active' => 'Create an active supplier automatically'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('supplier_bootstrap_mode', $policy->supplier_bootstrap_mode) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="new_item_mode" class="form-label">Unknown Items</label>
                            <select id="new_item_mode" name="new_item_mode" class="form-select" required>
                                @foreach(['review_only' => 'Stop for Item matching (recommended)', 'create_review_item' => 'Create an inactive Item for review', 'create_active_item' => 'Create an active Item automatically'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('new_item_mode', $policy->new_item_mode) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Storage provisions the isolated structured workload behind the selected domain agent. --}}
            <div class="card mb-3">
                <div class="card-header"><h2 class="h6 mb-0">AI assistance</h2></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="ai_mode" class="form-label">Use AI</label>
                            <select id="ai_mode" name="ai_mode" class="form-select" required>
                                @foreach(['off' => 'Off', 'fallback' => 'Only when supplier profile cannot finish the order (recommended)', 'always' => 'Verify every order'] as $value => $label)
                                    <option value="{{ $value }}"
                                            @selected(old('ai_mode', $policy->ai_mode) === $value)
                                            @disabled($value !== 'off' && $storageAgents->isEmpty())>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="ai_agent_id" class="form-label">Storage agent</label>
                            <select id="ai_agent_id" name="ai_agent_id" class="form-select @error('ai_agent_id') is-invalid @enderror">
                                <option value="">Select agent</option>
                                @if($policy->aiAgent && ! $storageAgents->contains('id', $policy->aiAgent->id))
                                    <option value="{{ $policy->aiAgent->id }}" selected disabled>{{ $policy->aiAgent->name }} (not currently available)</option>
                                @endif
                                @foreach($storageAgents as $agent)
                                    <option value="{{ $agent->id }}" @selected((int) old('ai_agent_id', $policy->ai_agent_id) === (int) $agent->id)>
                                        {{ $agent->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('ai_agent_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <div class="alert alert-light border mb-0 small">
                                AI only proposes structured order data. Nexum sends sanitized order evidence, validates the answer, and performs any allowed changes itself. If a supplier profile is missing, Nexum can create and activate a reusable profile after the same checks. The agent cannot use its tools or write from this workflow. The model is selected on the Storage agent.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Safety limits are evaluated before any purchase-order write. --}}
            <div class="card mb-3">
                <div class="card-header"><h2 class="h6 mb-0">Order limits</h2></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="small text-muted">An order outside these limits stops for manual review.</div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label for="amount_tolerance" class="form-label">Allowed amount difference</label>
                            <input type="number" min="0" max="1000" step="0.0001" id="amount_tolerance" name="amount_tolerance" class="form-control"
                                   value="{{ old('amount_tolerance', $policy->amount_tolerance) }}" required>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label for="max_lines" class="form-label">Maximum lines</label>
                            <input type="number" min="1" max="500" id="max_lines" name="max_lines" class="form-control"
                                   value="{{ old('max_lines', $policy->max_lines) }}" required>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label for="max_quantity_per_line" class="form-label">Maximum quantity per line</label>
                            <input type="number" min="1" max="1000000" id="max_quantity_per_line" name="max_quantity_per_line" class="form-control"
                                   value="{{ old('max_quantity_per_line', $policy->max_quantity_per_line) }}" required>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label for="max_order_total" class="form-label">Maximum order total (NOK)</label>
                            <input type="number" min="0" max="999999999999.99" step="0.01" id="max_order_total" name="max_order_total" class="form-control"
                                   value="{{ old('max_order_total', $policy->max_order_total) }}" required>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label for="max_new_items" class="form-label">Maximum new Items per order</label>
                            <input type="number" min="0" max="500" id="max_new_items" name="max_new_items" class="form-control"
                                   value="{{ old('max_new_items', $policy->max_new_items) }}" required>
                            <div class="form-text">Use at least 1 with automatic Item creation. A value of 0 disables it.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h2 class="h6 mb-0">Notifications</h2></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6 col-lg-3 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input type="checkbox" id="silent_success" name="silent_success" value="1" class="form-check-input"
                                       @checked(old('silent_success', $policy->silent_success))>
                                <label for="silent_success" class="form-check-label">Silent successful imports</label>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input type="checkbox" id="daily_digest_enabled" name="daily_digest_enabled" value="1" class="form-check-input"
                                       @checked(old('daily_digest_enabled', $policy->daily_digest_enabled))>
                                <label for="daily_digest_enabled" class="form-check-label">Daily digest enabled</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end mb-4">
                <button type="submit" class="btn btn-primary">Save settings</button>
            </div>
        </form>

        {{-- Immutable history provides a compact governance audit. --}}
        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <h2 class="h6 mb-0">Change history</h2>
                <span class="small text-muted">Current version {{ $policy->revision_number }}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Version</th><th>Active since</th><th>Changed by</th><th>Note</th></tr></thead>
                    <tbody>
                    @forelse($policy->revisions->sortByDesc('revision_number') as $history)
                        <tr>
                            <td class="fw-semibold">{{ $history->revision_number }}</td>
                            <td>{{ $history->activated_at?->format('d.m.Y H:i:s') ?: '-' }}</td>
                            <td>{{ $history->creator?->name ?: 'System' }}</td>
                            <td>{{ $history->reason ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">No saved versions yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
