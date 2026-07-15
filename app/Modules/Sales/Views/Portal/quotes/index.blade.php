@extends('customerportal::layouts.portal')

@section('title', 'Quotes')

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Portal Quote List -->
    <!-- ------------------------------------------------- -->
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">Quotes</h1>
            <div class="small text-muted">{{ $context->client->name }}{{ $context->site ? ' - '.$context->site->name : '' }}</div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Quote</th>
                        <th>Status</th>
                        <th>Expires</th>
                        <th class="text-end">Total inc. VAT</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($versions as $version)
                        <tr>
                            <td>
                                <a href="{{ route('customer-portal.quotes.show', $version) }}" class="fw-semibold text-decoration-none">
                                    {{ $version->quote->quote_key }} v{{ $version->version_number }}
                                </a>
                                <div class="small text-muted">{{ $version->title }}</div>
                            </td>
                            <td><span class="badge text-bg-light border">{{ $access->statusLabel($version) }}</span></td>
                            <td>{{ $version->expires_at?->format('Y-m-d') ?: '-' }}</td>
                            <td class="text-end fw-semibold">{{ number_format((float) $version->total_inc_vat, 2, ',', ' ') }} kr</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No visible quotes for this portal scope.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $versions->links() }}
    </div>
@endsection
