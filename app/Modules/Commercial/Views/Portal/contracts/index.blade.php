@extends('customerportal::layouts.portal')

@section('title', 'Contracts')

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Portal Contract List -->
    <!-- ------------------------------------------------- -->
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">Contracts</h1>
            <div class="small text-muted">{{ $context->client->name }}{{ $context->site ? ' - '.$context->site->name : '' }}</div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Contract</th>
                        <th>Status</th>
                        <th>Period</th>
                        <th class="text-end">Monthly</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($contracts as $contract)
                        <tr>
                            <td>
                                <a href="{{ route('customer-portal.contracts.show', $contract) }}" class="fw-semibold text-decoration-none">
                                    Contract #{{ $contract->id }}
                                </a>
                                <div class="small text-muted">{{ $contract->description ?: 'No description' }}</div>
                            </td>
                            <td><span class="badge text-bg-light border">{{ $access->statusLabel($contract) }}</span></td>
                            <td>
                                {{ $contract->start_date?->format('Y-m-d') ?: '-' }}
                                -
                                {{ $contract->end_date?->format('Y-m-d') ?: 'Ongoing' }}
                            </td>
                            <td class="text-end fw-semibold">{{ number_format((float) $contract->total_monthly_amount, 2, ',', ' ') }} kr</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No visible contracts for this portal scope.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $contracts->links() }}
    </div>
@endsection
