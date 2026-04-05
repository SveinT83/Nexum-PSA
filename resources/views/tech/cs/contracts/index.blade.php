@extends('layouts.default_tech')

@section('title', 'Contracts')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">Contracts</h2>
        <div>
            <a href="{{ route('tech.contracts.create') }}" class="btn btn-sm btn-primary">New Contract</a>
        </div>
    </div>
@endsection

@section('content')
    <div class="card mt-4">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Client</th>
                            <th>Status</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Monthly Price</th>
                            <th>Yearly profit</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($contracts as $contract)
                            <tr>
                                <td>#{{ $contract->id }}</td>
                                <td>
                                    @if($contract->client)
                                        <a href="{{ route('tech.clients.show', $contract->client) }}" class="fw-bold text-decoration-none">
                                            {{ $contract->client->name }}
                                        </a>
                                    @else
                                        <span class="text-muted">No Client</span>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $statusClass = match($contract->approval_status) {
                                            'approved', 'won' => 'success',
                                            'rejected', 'quote_lost' => 'danger',
                                            'draft' => 'secondary',
                                            'negotiation' => 'info',
                                            'sent_quote', 'sent_contract' => 'primary',
                                            default => 'secondary'
                                        };
                                        $statusLabel = match($contract->approval_status) {
                                            'quote_lost' => 'Quote Lost',
                                            'sent_quote' => 'Sent (Quote)',
                                            'sent_contract' => 'Sent (Contract)',
                                            default => ucfirst(str_replace('_', ' ', $contract->approval_status ?? 'Draft'))
                                        };
                                    @endphp
                                    <span class="badge text-bg-{{ $statusClass }}">
                                        {{ $statusLabel }}
                                    </span>
                                </td>
                                <td>{{ $contract->start_date ? $contract->start_date->format('d.m.Y') : '-' }}</td>
                                <td>{{ $contract->end_date ? $contract->end_date->format('d.m.Y') : '-' }}</td>
                                <td>{{ number_format($contract->total_monthly_amount, 2, ',', '.') }} kr</td>
                                <td>
                                    <span class="text-success fw-bold">
                                        {{ number_format($contract->yearly_profit, 2, ',', '.') }} kr
                                    </span>
                                </td>
                                <td class="text-end">
                                    @if($contract->isEditable())
                                        <a href="{{ route('tech.contracts.edit', $contract) }}" class="btn btn-sm btn-outline-warning">Edit Contract</a>
                                        <a href="{{ route('tech.contracts.services.edit', $contract) }}" class="btn btn-sm btn-outline-warning">Edit Services</a>
                                    @endif
                                    <a href="{{ route('tech.contracts.show', $contract) }}" class="btn btn-sm btn-outline-primary">Open</a>

                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    No contracts found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $contracts->links() }}
            </div>
        </div>
    </div>
@endsection

@section('sidebar')
@endsection

@section('rightbar')
    <x-card.default title="Overview">
        <ul class="list-unstyled">
            <li><strong>Total Contracts:</strong> {{ $contracts->total() }}</li>
            <li><strong>Clients without Contract:</strong> {{ $clientsWithoutContractsCount }}</li>
        </ul>
    </x-card.default>
@endsection
