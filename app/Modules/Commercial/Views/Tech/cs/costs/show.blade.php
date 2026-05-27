@extends('layouts.default_tech')

@section('title', $cost->name)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>{{ $cost->name }}</h1>
        <div>
            <x-buttons.back url="{{ route('tech.costs.index') }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Alert message -->
    <!-- ------------------------------------------------- -->
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <!-- ------------------------------------------------- -->
    <!-- Cost details -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">Cost Details</h2>
            <x-buttons.editlink url="{{ route('tech.costs.edit', $cost) }}" class="mb-0">Edit</x-buttons.editlink>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="text-muted small text-uppercase">Cost</div>
                    <div class="fw-semibold">{{ number_format((float) $cost->cost, 2, ',', '.') }} kr</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small text-uppercase">Unit</div>
                    <div class="fw-semibold">{{ $cost->unit->name ?? '—' }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small text-uppercase">Recurrence</div>
                    <div class="fw-semibold">{{ ucfirst($cost->recurrence) }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small text-uppercase">Vendor</div>
                    <div class="fw-semibold">{{ $cost->vendor->name ?? '—' }}</div>
                </div>
                <div class="col-12">
                    <div class="text-muted small text-uppercase">Note</div>
                    <div>{{ filled($cost->note) ? $cost->note : '—' }}</div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
@endsection
