@extends('layouts.default_tech')

<!-- Edit or new Term or Legal Policy? -->
@php
    $isEdit = isset($term) && !request()->routeIs('tech.legal.show');
    $isShow = request()->routeIs('tech.legal.show');
    $providerManaged = isset($term) && $term->isProviderManaged();
    $disabled = ($isShow || $providerManaged) ? 'disabled' : null;
@endphp

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>{{ $isShow ? ($term->name ?? 'Legal & Term') : ($isEdit ? 'Edit Legal & Term' : 'New Legal & Term') }}</h1>

        <div>
            <!-- Edit button -->
            @if($isShow && isset($term) && ! $providerManaged)
                <x-buttons.editlink url="{{ route('tech.legal.edit', $term) }}" class="mb-0">Edit</x-buttons.editlink>
            @endif

            <!-- Back button -->
            <x-buttons.back url="{{ route('tech.legal.index') }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    @if($providerManaged)
        <div class="alert alert-info">
            <div class="fw-semibold">Provider-managed document</div>
            <div class="small">This version is synchronized from Cloud Factory and is read-only in Nexum.</div>
        </div>
    @endif

    <!-- ------------------------------------------------- -->
    <!-- Form -->
    <!-- ------------------------------------------------- -->

    <x-forms.form-card
        title="{{ $providerManaged ? 'Provider legal document' : 'Nexum legal document' }}"
        action="{{ $isShow
        ? route('tech.legal.edit', $term ?? null)
        : ($isEdit ? route('tech.legal.update', $term ?? null) : route('tech.legal.store')) }}"
        method="{{ $isShow ? 'get' : 'post' }}"
        button-text="{{ $isEdit ? 'Update' : ($isShow ? 'Edit' : 'Save') }}">

        @csrf
        @if($isEdit)
            @method('PUT')
        @endif

        <!-- ------------------------------------------------- -->
        <!-- Name -->
        <!-- ------------------------------------------------- -->
        <div class="row">

            <!-- Name -->
            <div class="col-md-4 mb-3">
                <x-forms.input_text name="name" labelName="Name:" value="{{$term->name ?? ''}}" inputVar="required {{$disabled}}"></x-forms.input_text>
            </div>

            <!-- type -->
            <div class="col-md-4 mb-3">
                <x-forms.select name="type" labelName="Type:" inputVar="required {{$disabled}}">
                    <option value="terms" {{ (isset($term) && $term->type == 'terms') ? 'selected' : '' }}>Terms</option>
                    <option value="dpa" {{ (isset($term) && $term->type == 'dpa') ? 'selected' : '' }}>DPA</option>
                    <option value="legal" {{ (isset($term) && $term->type == 'legal') ? 'selected' : '' }}>Legal</option>
                    <option value="sla" {{ (isset($term) && $term->type == 'sla') ? 'selected' : '' }}>SLA</option>
                    <option value="general" {{ (isset($term) && $term->type == 'general') ? 'selected' : '' }}>General</option>
                </x-forms.select>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Content -->
        <!-- ------------------------------------------------- -->
        <div class="row mt-3">
            <!-- Cost Name -->
            <div class="col-md-12 mb-3">
                <x-forms.textarea name="content" labelName="Content" vars="{{$disabled}}">{{$term->content ?? ''}}</x-forms.textarea>
            </div>
        </div>

    </x-forms.form-card>

    @if($isShow && isset($term) && ! $providerManaged)
        <div class="card mt-3 border-danger">
            <div class="card-header text-danger fw-bold">
                Danger zone
            </div>
            <div class="card-body">
                @if(! $term->isInUse())
                    <p class="text-muted">
                        This legal or term is not connected to any services or packages and can be deleted.
                    </p>

                    <form method="POST" action="{{ route('tech.legal.delete', $term) }}" onsubmit="return confirm('Are you sure you want to delete this legal or term?')">
                        @csrf
                        @method('DELETE')

                        <button type="submit" class="btn btn-danger">
                            Delete legal or term
                        </button>
                    </form>
                @else
                    <p class="text-muted mb-0">
                        This legal or term cannot be deleted because it is currently in use.
                    </p>
                @endif
            </div>
        </div>
    @endif

@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')

    <!-- ------------------------------------------------- -->
    <!-- Services -->
    <!-- ------------------------------------------------- -->
    @if(isset($term))
        <x-card.default title="Document version">
            <dl class="row small mb-0">
                <dt class="col-5 text-muted">Origin</dt>
                <dd class="col-7">{{ $providerManaged ? 'Provider' : 'Nexum' }}</dd>
                <dt class="col-5 text-muted">Version</dt>
                <dd class="col-7">{{ $term->currentVersion?->version_label ?: '1' }}</dd>
                <dt class="col-5 text-muted">Status</dt>
                <dd class="col-7">{{ ucfirst(str_replace('_', ' ', $term->sync_status ?: 'current')) }}</dd>
                @if($term->last_checked_at)
                    <dt class="col-5 text-muted">Last checked</dt>
                    <dd class="col-7">{{ $term->last_checked_at->format('Y-m-d H:i') }}</dd>
                @endif
            </dl>
        </x-card.default>
    @endif

    <x-card.default title="Connected Services">
        @if(isset($term) && $term->services->count() > 0)
            <ul class="list-group list-group-flush">
                @foreach($term->services as $service)
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <div>
                            <span class="fw-bold d-block">{{ $service->name }}</span>
                            <small class="text-muted">{{ $service->sku }}</small>
                        </div>
                        <a href="{{ route('tech.services.edit', $service) }}" class="btn btn-sm btn-link p-0 bi bi-box-arrow-in-right"></a>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-muted mb-0">No services connected to this term.</p>
        @endif
    </x-card.default>

@endsection
