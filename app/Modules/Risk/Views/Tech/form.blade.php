@extends('layouts.default_tech')

{{--
    Risk Assessment Form

    This Blade view is shared by create and edit actions. The controller passes
    either a new unsaved RiskAssessment or an existing one, and the $isEdit flag
    controls the HTTP method, button labels, and back/cancel behavior.

    Scope handling is intentionally visible here because it is a UI concern:
    users choose "Internal" or "Client Specific". Persistence translation is
    handled by StoreRiskAssessment / UpdateRiskAssessment, where internal means
    client_id is saved as null.
--}}

@php
    $isEdit = $risk->exists;
    $scope = old('scope', $risk->client_id ? 'client' : 'internal');
@endphp

@section('title', $isEdit ? 'Edit Risk Assessment' : 'Risk Assessment')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h1 class="h4 mb-0">{{ $isEdit ? 'Edit Risk Assessment' : 'Create Risk Assessment' }}</h1>

        <x-buttons.back url="{{ $isEdit ? route('tech.risk.show', $risk) : route('tech.risk.index') }}"> Back to Risk</x-buttons.back>
    </div>
@endsection

@section('content')
    <div class="card">
        <div class="card-body">
            <form action="{{ $isEdit ? route('tech.risk.update', $risk) : route('tech.risk.store') }}" method="POST">
                @csrf
                @if($isEdit)
                    @method('PUT')
                @endif

                <div class="mb-4">
                    <x-forms.input_text
                        name="title"
                        labelName="Assessment Title"
                        placeholder="e.g., Annual IT Risk Assessment"
                        value="{{ old('title', $risk->title) }}"
                        inputVar="required"
                    />
                </div>

                <div class="mb-4">
                    <x-forms.textarea
                        name="description"
                        labelName="Description"
                        placeholder="Provide a brief overview of the assessment scope..."
                        value="{{ old('description', $risk->description) }}"
                    />
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <x-forms.select name="scope" labelName="Assessment Scope">
                            <option value="internal" {{ $scope === 'internal' ? 'selected' : '' }}>Internal (Company Private)</option>
                            <option value="client" {{ $scope === 'client' ? 'selected' : '' }}>Client Specific</option>
                        </x-forms.select>
                    </div>

                    <div class="col-md-6" id="client_selector_wrapper" style="display: {{ $scope === 'client' ? 'block' : 'none' }};">
                        <x-forms.select name="client_id" labelName="Select Client">
                            <option value="">-- Choose Client --</option>
                            @foreach($clients as $client)
                                <option value="{{ $client->id }}" {{ (old('client_id', $risk->client_id ?? session('active_client_id')) == $client->id) ? 'selected' : '' }}>
                                    {{ $client->name }}
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Update Assessment' : 'Create Assessment' }}</button>
                    @if($isEdit)
                        <a href="{{ route('tech.risk.show', $risk) }}" class="btn btn-outline-secondary">Cancel</a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const scopeSelect = document.getElementById('scope');
            const clientWrapper = document.getElementById('client_selector_wrapper');

            if (!scopeSelect || !clientWrapper) {
                return;
            }

            scopeSelect.addEventListener('change', function () {
                clientWrapper.style.display = this.value === 'client' ? 'block' : 'none';
            });
        });
    </script>
@endsection

@section('sidebar')
    <x-nav.work-menu />
@endsection
