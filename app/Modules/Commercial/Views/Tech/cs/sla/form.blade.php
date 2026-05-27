@extends('layouts.default_tech')

<!-- Edit or new SLA Policy? -->
@php
    $isEdit = isset($sla) && !request()->routeIs('tech.sla.show');
    $isShow = request()->routeIs('tech.sla.show');
    $disabled = $isShow ? 'disabled' : null;
@endphp

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>{{ $isShow ? ($sla->name ?? 'SLA Policy') : ($isEdit ? 'Edit SLA Policy' : 'Create SLA Policy') }}</h1>
        <div class="d-flex align-items-center gap-2">
            <!-- Edit button -->
            @if($isShow && isset($sla))
                <x-buttons.editlink url="{{ route('tech.sla.edit', $sla) }}" class="mb-0">Edit</x-buttons.editlink>
            @endif

            <!-- Back button -->
            <x-buttons.back url="{{ route('tech.sla.index') }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')

    @if($isShow && isset($sla))
        @php
            // The show view renders SLA values as operational policy cards instead of disabled form fields.
            $responsePolicies = [
                [
                    'label' => 'Low',
                    'icon' => 'bi-shield-check',
                    'tone' => 'text-success',
                    'firstResponse' => $sla->low_firstResponse,
                    'firstResponseType' => $sla->low_firstResponse_type,
                    'onsite' => $sla->low_onsite,
                    'onsiteType' => $sla->low_onsite_type,
                ],
                [
                    'label' => 'Medium',
                    'icon' => 'bi-shield-exclamation',
                    'tone' => 'text-warning',
                    'firstResponse' => $sla->medium_firstResponse,
                    'firstResponseType' => $sla->medium_firstResponse_type,
                    'onsite' => $sla->medium_onsite,
                    'onsiteType' => $sla->medium_onsite_type,
                ],
                [
                    'label' => 'High',
                    'icon' => 'bi-shield-fill-exclamation',
                    'tone' => 'text-danger',
                    'firstResponse' => $sla->high_firstResponse,
                    'firstResponseType' => $sla->high_firstResponse_type,
                    'onsite' => $sla->high_onsite,
                    'onsiteType' => $sla->high_onsite_type,
                ],
            ];
        @endphp

        <!-- ------------------------------------------------- -->
        <!-- SLA Details -->
        <!-- ------------------------------------------------- -->
        <section class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between py-2">
                <h3 class="h6 mb-0">SLA Details</h3>
                <span class="badge text-bg-light border">
                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                    {{ count($responsePolicies) }} priority levels
                </span>
            </div>
            <div class="card-body py-3">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="small text-muted">Name</div>
                        <div class="fw-semibold">
                            {{ $sla->name }}
                            @if($sla->is_default)
                                <span class="badge text-bg-primary ms-1">Default</span>
                            @endif
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="small text-muted">Description</div>
                        <div>{{ $sla->description ?: 'No description' }}</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ------------------------------------------------- -->
        <!-- Response Time Cards -->
        <!-- ------------------------------------------------- -->
        <section>
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h3 class="h6 mb-0">Response Time</h3>
                <div class="small text-muted">First response and onsite targets by priority</div>
            </div>

            <div class="row g-3">
                @foreach($responsePolicies as $policy)
                    <div class="col-md-4">
                        <article class="card h-100">
                            <div class="card-header d-flex align-items-center justify-content-between py-2">
                                <h4 class="h6 mb-0">
                                    <i class="bi {{ $policy['icon'] }} {{ $policy['tone'] }}" aria-hidden="true"></i>
                                    {{ $policy['label'] }}
                                </h4>
                                <span class="badge text-bg-light border">{{ $policy['firstResponse'] }} {{ $policy['firstResponseType'] }}</span>
                            </div>
                            <div class="card-body py-3">
                                <div class="d-grid gap-3">
                                    <div>
                                        <div class="small text-muted">First response</div>
                                        <div class="fs-5 fw-semibold">{{ $policy['firstResponse'] }} {{ $policy['firstResponseType'] }}</div>
                                    </div>
                                    <div>
                                        <div class="small text-muted">Onsite</div>
                                        <div class="fs-5 fw-semibold">{{ $policy['onsite'] }} {{ $policy['onsiteType'] }}</div>
                                    </div>
                                </div>
                            </div>
                        </article>
                    </div>
                @endforeach
            </div>
        </section>
    @else
        <x-forms.form-default
            action="{{ $isEdit ? route('tech.sla.update', $sla ?? null) : route('tech.sla.store') }}"
            method="post"
            button-text="{{ $isEdit ? 'Update' : 'Save' }}">

            @if($isEdit)
                @method('PUT')
            @endif

            <!-- ------------------------------------------------- -->
            <!-- SLA Details -->
            <!-- ------------------------------------------------- -->
            <section class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between py-2">
                    <h3 class="h6 mb-0">SLA Details</h3>
                    <span class="small text-muted">Required policy metadata</span>
                </div>
                <div class="card-body py-3">
                    <div class="row g-3">
                        <!-- SLA Name -->
                        <div class="col-md-4">
                            <x-forms.input_text name="name" labelName="Name:" value="{{$sla->name ?? ''}}" inputVar="required {{$disabled ?? ''}}"></x-forms.input_text>
                        </div>

                        <div class="col-md-8">
                            <x-forms.textarea name="description" labelName="Description" vars="{{$disabled ?? ''}}">{{$sla->description ?? ''}}</x-forms.textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input type="hidden" name="is_default" value="0">
                                <input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1" @checked(old('is_default', $sla->is_default ?? false)) {{ $disabled ?? '' }}>
                                <label class="form-check-label" for="is_default">Default SLA policy</label>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ------------------------------------------------- -->
            <!-- Response Time Form -->
            <!-- ------------------------------------------------- -->
            @include("commercial::Tech.cs.sla.partials.responseTime", ['sla' => $sla ?? null])

        </x-forms.form-default>
    @endif
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
@endsection
