@extends('layouts.default_tech')

@section('title', 'Sales Lead')

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="mb-0">{{ $lead->name }}</h1>
            <div class="text-muted small">Sales lead candidate</div>
        </div>
        <x-buttons.back url="{{ route('tech.sales.leads.index') }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    @php
        $lead->loadMissing(['salesCategory', 'tags']);
        $contactsCount = $lead->contacts()->count();
        $sitesCount = $lead->sites()->count();
        $assetsCount = $lead->assets()->count();
        $contractsCount = $lead->contracts()->count();
    @endphp

    <!-- Sales lead detail -->
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                    <h2 class="h5 mb-0">Lead Summary</h2>
                    <span class="badge text-bg-light border">Heat {{ $lead->lead_temperature ?? 1 }}/5</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="text-muted small text-uppercase fw-semibold">Organization number</div>
                            <div>{{ $lead->org_no ?: '-' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small text-uppercase fw-semibold">Billing email</div>
                            <div>{{ $lead->billing_email ?: '-' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small text-uppercase fw-semibold">Website</div>
                            <div>
                                @if($lead->website)
                                    <a href="{{ $lead->website }}" target="_blank" rel="noopener">{{ $lead->website }}</a>
                                @else
                                    -
                                @endif
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small text-uppercase fw-semibold">Category</div>
                            <div>{{ $lead->salesCategory?->name ?? 'Uncategorized' }}</div>
                        </div>
                    </div>

                    @if($lead->tags->isNotEmpty())
                        <div class="d-flex flex-wrap gap-1 mt-3">
                            @foreach($lead->tags as $tag)
                                <span class="badge text-bg-secondary">{{ $tag->name }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="h5 mb-0">Start Sales Process</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('tech.sales.leads.start', $lead) }}" class="row g-3">
                        @csrf
                        <div class="col-md-8">
                            <label class="form-label">Title</label>
                            <input type="text" name="title" class="form-control" value="{{ old('title', $lead->name.' service opportunity') }}" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select">
                                @foreach(\App\Modules\Sales\Actions\EnsureSalesDefaults::TYPES as $key => $label)
                                    <option value="{{ $key }}" @selected(old('type') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Next follow-up</label>
                            <input type="datetime-local" name="next_follow_up_at" class="form-control" value="{{ old('next_follow_up_at') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Next action</label>
                            <select name="next_follow_up_type" class="form-select">
                                <option value="">No action selected</option>
                                @foreach(\App\Modules\Sales\Actions\EnsureSalesDefaults::NEXT_ACTIONS as $key => $label)
                                    <option value="{{ $key }}" @selected(old('next_follow_up_type') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Need / summary</label>
                            <textarea name="needs" class="form-control" rows="3">{{ old('needs') }}</textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-play-circle me-1" aria-hidden="true"></i>
                                Start sales process
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mb-3">
                <div class="card-header">
                    <h2 class="h5 mb-0">Footprint</h2>
                </div>
                <div class="list-group list-group-flush">
                    <div class="list-group-item d-flex justify-content-between">
                        <span>Contacts</span>
                        <span class="badge text-bg-light border">{{ $contactsCount }}</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between">
                        <span>Sites</span>
                        <span class="badge text-bg-light border">{{ $sitesCount }}</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between">
                        <span>Assets</span>
                        <span class="badge text-bg-light border">{{ $assetsCount }}</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between">
                        <span>Contracts</span>
                        <span class="badge text-bg-light border">{{ $contractsCount }}</span>
                    </div>
                </div>
            </div>

            <div class="d-grid gap-2">
                <a href="{{ route('tech.clients.show', $lead) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-building me-1" aria-hidden="true"></i>
                    Open client
                </a>
                <a href="{{ route('tech.contracts.create') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-plus me-1" aria-hidden="true"></i>
                    Create contract
                </a>
            </div>
        </div>
    </div>
@endsection
