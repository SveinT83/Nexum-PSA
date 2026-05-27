@extends('layouts.default_tech')

@section('title', 'Rates')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Rates</h1>
        <div>
            <x-buttons.back url="{{ route('tech.sales.index') }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            <div class="fw-semibold">The rate could not be saved.</div>
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Rate catalogue -->
    <div class="card">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <h2 class="h6 mb-0">Rate Catalogue</h2>
            <div class="d-flex align-items-center gap-2">
                <span class="badge text-bg-light border">{{ $rates->count() }} rates</span>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createRateModal">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    New Rate
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Type</th>
                        <th>Unit</th>
                        <th class="text-end">Rate ex VAT</th>
                        <th>Scope</th>
                        <th>Status</th>
                        <th class="text-end">Edit</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rates as $rate)
                        <tr>
                            <td>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm p-0 text-decoration-none fw-semibold"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editRateModal{{ $rate->id }}">
                                    {{ $rate->name }}
                                </button>
                                @if($rate->description)
                                    <div class="small text-muted">{{ \Illuminate\Support\Str::limit($rate->description, 90) }}</div>
                                @endif
                            </td>
                            <td><code>{{ $rate->code }}</code></td>
                            <td>{{ $rateTypes[$rate->rate_type] ?? ucfirst($rate->rate_type) }}</td>
                            <td>{{ $units[$rate->unit] ?? ucfirst($rate->unit) }}</td>
                            <td class="text-end">{{ number_format((float) $rate->amount_ex_vat, 2, ',', ' ') }} {{ $rate->currency }}</td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    @if($rate->applies_with_contract)
                                        <span class="badge text-bg-light border">With contract</span>
                                    @endif
                                    @if($rate->applies_without_contract)
                                        <span class="badge text-bg-light border">Without contract</span>
                                    @endif
                                    @if(! $rate->applies_with_contract && ! $rate->applies_without_contract)
                                        <span class="text-muted small">Manual only</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <span class="badge {{ $rate->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ $rate->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="text-end">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editRateModal{{ $rate->id }}">
                                    Edit
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-muted py-4 text-center">No rates have been created yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create rate modal -->
    <div class="modal fade" id="createRateModal" tabindex="-1" aria-labelledby="createRateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form method="POST" action="{{ route('tech.rates.store') }}" class="modal-content">
                @csrf
                <div class="modal-header">
                    <h2 class="modal-title h5" id="createRateModalLabel">New Rate</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @include('commercial::Tech.cs.rates.partials.form', [
                        'rate' => null,
                        'rateTypes' => $rateTypes,
                        'units' => $units,
                    ])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">Save Rate</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit rate modals -->
    @foreach($rates as $rate)
        <div class="modal fade" id="editRateModal{{ $rate->id }}" tabindex="-1" aria-labelledby="editRateModal{{ $rate->id }}Label" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <form method="POST" action="{{ route('tech.rates.update', $rate) }}" class="modal-content">
                    @csrf
                    @method('PUT')
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="editRateModal{{ $rate->id }}Label">Edit Rate</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @include('commercial::Tech.cs.rates.partials.form', [
                            'rate' => $rate,
                            'rateTypes' => $rateTypes,
                            'units' => $units,
                        ])
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach
@endsection
