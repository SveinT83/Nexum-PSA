@extends('layouts.default_tech')

{{--
    Create Risk Assessment View

    Purpose: Provides the interface to initialize a new Risk Assessment.

    Workflow:
    1. Scope selection: Internal (company private) vs. Client specific.
    2. Client linkage: Dynamic dropdown shows only when 'Client Specific' is chosen.
    3. Status defaults: All new assessments are initialized as 'new' in the controller.
--}}

@section('title', 'Risk Assessment')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h1 class="h4 mb-0">Create Risk Assessment</h1>
        <a href="{{ route('tech.risk.index') }}" class="btn btn-sm btn-secondary">Back to list</a>
    </div>
@endsection

@section('content')
    <div class="card">
        <div class="card-body">
            <form action="{{ route('tech.risk.store') }}" method="POST">
                @csrf

                <div class="mb-4">
                    <x-forms.input_text
                        name="title"
                        labelName="Assessment Title"
                        placeholder="e.g., Annual IT Risk Assessment 2024"
                        value="{{ old('title') }}"
                        inputVar="required"
                    />
                </div>

                <div class="mb-4">
                    <x-forms.textarea
                        name="description"
                        labelName="Description"
                        placeholder="Provide a brief overview of the assessment scope..."
                        value="{{ old('description') }}"
                    />
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        @php
                            $isClient = old('scope') == 'client' || (!old('scope') && session('active_client_id'));
                            $isInternal = old('scope') == 'internal' || (!old('scope') && !session('active_client_id'));
                        @endphp
                        <x-forms.select name="scope" labelName="Assessment Scope">
                            <option value="internal" {{ $isInternal ? 'selected' : '' }}>Internal (Company Private)</option>
                            <option value="client" {{ $isClient ? 'selected' : '' }}>Client Specific</option>
                        </x-forms.select>
                    </div>

                    <div class="col-md-6" id="client_selector_wrapper" style="display: {{ $isClient ? 'block' : 'none' }};">
                        <x-forms.select name="client_id" labelName="Select Client">
                            <option value="">-- Choose Client --</option>
                            @foreach($clients as $client)
                                <option value="{{ $client->id }}" {{ (old('client_id', session('active_client_id')) == $client->id) ? 'selected' : '' }}>
                                    {{ $client->name }}
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">Create Assessment</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Standard form.select component sets id equal to name
            const scopeSelect = document.getElementById('scope');
            const clientWrapper = document.getElementById('client_selector_wrapper');

            if (scopeSelect && clientWrapper) {
                scopeSelect.addEventListener('change', function() {
                    if (this.value === 'client') {
                        clientWrapper.style.display = 'block';
                    } else {
                        clientWrapper.style.display = 'none';
                    }
                });
            }
        });
    </script>
@endsection
