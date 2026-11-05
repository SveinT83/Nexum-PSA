@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1>Client time consumption</h1>
        <x-buttons.back :url="$client ? route('tech.clients.show', ['client' => $client, 'tab' => 'time-usage']) : route('tech.reports.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    {{-- Customer selection and inclusive work-date filters --}}
    <div class="card mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                @if($client)
                    <input type="hidden" name="client_id" value="{{ $client->id }}">
                    <div class="col-lg-3"><span class="fw-semibold">{{ $client->name }}</span><br><span class="text-muted">{{ $client->client_number }}</span>
                        <a href="{{ route('tech.clients.time-report') }}" class="ms-2">Change Client</a></div>
                @else
                    <div class="col-lg-4"><label for="client-search" class="form-label">Client name, number or website</label>
                        <input id="client-search" name="q" value="{{ request('q') }}" class="form-control" maxlength="100"></div>
                @endif
                <div class="col-sm-4 col-lg-3"><label for="date-from" class="form-label">Work date from</label>
                    <input id="date-from" type="date" name="date_from" value="{{ request('date_from') }}" class="form-control"></div>
                <div class="col-sm-4 col-lg-3"><label for="date-to" class="form-label">Work date to</label>
                    <input id="date-to" type="date" name="date_to" value="{{ request('date_to') }}" class="form-control"></div>
                <div class="col-auto"><button class="btn btn-primary"><i class="bi bi-search me-1"></i>{{ $client ? 'Apply' : 'Find Client' }}</button></div>
            </form>
            @if($errors->any())
                <div class="alert alert-danger mt-2 mb-0" role="alert">{{ $errors->first() }}</div>
            @endif
            @if(!$client && request()->filled('q'))
                <div class="list-group mt-2">
                    @forelse($clients as $candidate)
                        <a class="list-group-item list-group-item-action" href="{{ route('tech.clients.time-report', array_filter(['client_id' => $candidate->id, 'date_from' => request('date_from'), 'date_to' => request('date_to')])) }}">{{ $candidate->client_number }} — {{ $candidate->name }}</a>
                    @empty
                        <div class="list-group-item text-muted">No matching Clients.</div>
                    @endforelse
                </div>
                <div class="small text-muted mt-1">Up to 25 matches. Refine your search if needed.</div>
            @endif
        </div>
    </div>
    @if($report)
        {{-- Internal export actions use the exact same filters and permission checks --}}
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <span class="badge text-bg-warning">Internal report — contains employee names and internal descriptions</span>
            <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.clients.time-report', array_filter(['client_id' => $client->id, 'date_from' => $report['from'], 'date_to' => $report['to'], 'format' => 'xlsx'])) }}"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</a>
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.clients.time-report', array_filter(['client_id' => $client->id, 'date_from' => $report['from'], 'date_to' => $report['to'], 'format' => 'pdf'])) }}"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
            </div>
        </div>
        @include('clients::Tech.partials.time-report-data', $report)
    @else
        <p class="text-muted">Find and select a Client to view its complete time history. Leave dates empty for all history.</p>
    @endif
@endsection
