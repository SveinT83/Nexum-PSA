@extends('customerportal::layouts.portal')

@section('title', 'Avtaler')

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Portal Contract List -->
    <!-- ------------------------------------------------- -->
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">Avtaler</h1>
            <div class="small text-muted">{{ $context->client->name }}{{ $context->site ? ' - '.$context->site->name : '' }}</div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Avtale</th>
                        <th>Status</th>
                        <th>Periode</th>
                        <th class="text-end">Månedlig beløp</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($contracts as $contract)
                        @php($customerDocument = $customerDocuments[$contract->id] ?? null)
                        @php($documentState = $customerDocumentReadiness[$contract->id] ?? ['ready' => false, 'message' => 'Kundedokumentet er sperret i påvente av manuell verifisering.'])
                        @if($customerDocument)
                            <tr>
                                <td>
                                    <a href="{{ route('customer-portal.contracts.show', $contract) }}" class="fw-semibold text-decoration-none">
                                        {{ $customerDocument['document']['type'] }} #{{ $customerDocument['document']['contract_number'] }}
                                    </a>
                                    <div class="small text-muted">{{ $customerDocument['description'] ?: 'Ingen beskrivelse' }}</div>
                                </td>
                                <td><span class="badge text-bg-light border">{{ $customerDocument['document']['status'] }}</span></td>
                                <td>
                                    {{ $customerDocument['dates']['start']['value'] ?: '-' }}
                                    –
                                    {{ $customerDocument['dates']['end']['value'] ?: 'Løpende' }}
                                </td>
                                <td class="text-end fw-semibold">{{ $customerDocument['totals']['monthly']['display'] }}</td>
                            </tr>
                        @else
                            <tr>
                                <td>
                                    <span class="fw-semibold">Kundedokument #{{ $contract->id }}</span>
                                    <div class="small text-muted">{{ $documentState['message'] }}</div>
                                </td>
                                <td><span class="badge text-bg-warning">Under manuell kontroll</span></td>
                                <td aria-label="Periode utilgjengelig">—</td>
                                <td class="text-end fw-semibold" aria-label="Beløp utilgjengelig">—</td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">Ingen synlige avtaler for denne portaltilgangen.</td>
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
