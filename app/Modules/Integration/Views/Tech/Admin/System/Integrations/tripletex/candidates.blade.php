@extends('layouts.default_tech')
@section('title', 'Tripletex mapping candidates')
@section('pageHeader')
    <h1 class="h3">Tripletex reference data</h1>
@endsection
@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection
@section('content')
    {{-- Provider readback only: no name-based employee matching or automatic activation. --}}
    <p>{{ $connection->name }} &middot; Company {{ $company['company_id'] }}</p>
    <x-buttons.back :url="route('tech.admin.system.integrations.tripletex.index')">Back to settings</x-buttons.back>
    @foreach($candidates as $kind => $rows)
        <section class="card mb-3">
            <div class="card-header">{{ ucfirst($kind) }}</div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead><tr><th scope="col">Tripletex ID</th><th scope="col">Name</th></tr></thead>
                    <tbody>
                    @forelse($rows as $row)
                        <tr><td>{{ $row['id'] }}</td><td>{{ $row['name'] ?? trim(($row['firstName'] ?? '').' '.($row['lastName'] ?? '')) }}</td></tr>
                    @empty
                        <tr><td colspan="2">No accessible records.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach
@endsection
