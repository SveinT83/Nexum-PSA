@extends('layouts.default_tech')

{{--
    Documentation Editing View

    This view renders an edit form for an existing documentation record.
    It dynamically generates form fields based on the 'template_snapshot_json' column,
    ensuring that the documentation structure remains consistent with the time it was created,
    even if the original template is later modified.

    Fields are pre-populated with data stored in 'data_json'.
--}}

@section('title', 'Edit Documentation: ' . $documentation->title)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <div>
            <h1 class="h4 mb-0">Edit: {{ $documentation->title }}</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tech.documentations.index') }}">Documentations</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tech.documentations.show', $documentation->id) }}">{{ $documentation->title }}</a></li>
                    <li class="breadcrumb-item active">Edit</li>
                </ol>
            </nav>
        </div>

        <div>
            <span class="badge bg-secondary">Scope: {{ ucfirst($documentation->scope_type) }}</span>
        </div>
    </div>
@endsection

@section('content')
    <div class="card mt-4">
        <div class="card-body">
            <form action="{{ route('tech.documentations.update', $documentation->id) }}" method="POST">
                @csrf
                @method('PUT')

                <div class="row">
                    <div class="col-md-12 mb-3">
                        <x-forms.input_text Name="title" labelName="Dokument Tittel" :value="$documentation->title" />
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <x-forms.select name="client_id" labelName="Klient">
                            <option value="">Ingen klient (Internt)</option>
                            @foreach($clients as $client)
                                <option value="{{ $client->id }}" {{ $documentation->client_id == $client->id ? 'selected' : '' }}>
                                    {{ $client->name }}
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <x-forms.select name="site_id" labelName="Site">
                            <option value="">Velg site...</option>
                            @foreach($sites as $site)
                                <option value="{{ $site->id }}" {{ $documentation->site_id == $site->id ? 'selected' : '' }}>
                                    {{ $site->name }}
                                </option>
                            @endforeach
                        </x-forms.select>
                    </div>
                </div>

                <hr class="my-4">
                {{--
                    Render dynamic template fields.
                    Logic handles section headers (rowStart) and populates data from 'data_json'.
                --}}
                <h5>Template Fields ({{ $documentation->template->name ?? 'N/A' }})</h5>

                <div class="row">
                    @foreach($fields as $field)
                        @php
                            $fieldName = $field['Name'] ?? null;
                            $fieldValue = $data[$fieldName] ?? '';

                            // Layout handling logic
                            $isRowStart = isset($field['layout']) && $field['layout'] == "rowStart";
                            $isRowEnd = isset($field['layout']) && $field['layout'] == "rowEnd";
                            $colClass = (isset($field['type']) && $field['type'] == 'textarea') ? 'col-md-12' : 'col-md-3';

                            if ($isRowStart) {
                                 $colClass = 'col-md-12';
                            }
                        @endphp

                        {{-- Render section headers --}}
                        @if($isRowStart)
                            <div class="col-12 mt-4"><hr>@if(isset($field['labelName']))<h6 class="text-primary fw-bold">{{ $field['labelName'] }}</h6>@endif</div>
                        @endif

                        {{-- Render data-holding input fields --}}
                        @if($fieldName)
                            <div class="{{ $colClass }} mb-3">
                                @if(isset($field['type']) && ($field['type'] == 'text' || $field['type'] == 'date'))
                                    <x-forms.input_text
                                        :Name="$fieldName"
                                        :labelName="$field['labelName'] ?? $fieldName"
                                        :type="$field['type']"
                                        :value="$fieldValue"
                                    />
                                @endif

                                @if(isset($field['type']) && ($field['type'] == 'checkbox'))
                                    <x-forms.checkbox
                                        :Name="$fieldName"
                                        :id="$fieldName"
                                        :labelName="$field['labelName'] ?? $fieldName"
                                        :enabled="$fieldValue ? 'checked' : ''"
                                    />
                                @endif

                                @if(isset($field['type']) && $field['type'] == 'textarea')
                                    <x-forms.textarea
                                        :Name="$fieldName"
                                        :labelName="$field['labelName'] ?? $fieldName"
                                        :value="$fieldValue"
                                    />
                                @endif

                                @if(isset($field['type']) && $field['type'] == 'select')
                                    <x-forms.select
                                        :name="$fieldName"
                                        :labelName="$field['labelName'] ?? $fieldName">
                                        <option value="">Velg...</option>
                                        @foreach($field['options'] as $optionValue => $optionLabel)
                                            @php
                                                $val = is_int($optionValue) ? $optionLabel : $optionValue;
                                                $isSelected = $fieldValue == $val;
                                            @endphp
                                            <option value="{{ $val }}" {{ $isSelected ? 'selected' : '' }}>
                                                {{ $optionLabel }}
                                            </option>
                                        @endforeach
                                    </x-forms.select>
                                @endif
                            </div>
                        @endif
                    @endforeach
                </div>

                <div class="mt-4 pt-3 border-top">
                    <button type="submit" class="btn btn-primary">Lagre endringer</button>
                    <a href="{{ route('tech.documentations.show', $documentation->id) }}" class="btn btn-outline-secondary">Avbryt</a>
                </div>
            </form>
        </div>
    </div>
@endsection
