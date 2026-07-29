@extends('layouts.default_tech')

{{--
    Documentation Creation View

    This view handles the multi-step process of creating a new documentation record:
    1. Initial state: Asks the user to select a category (template).
    2. Template selected: Renders a dynamic form based on the selected category's fields.

    The form uses a global context (active_client_id/active_site_id) stored in the session,
    but also allows changing this context via the <x-context.selector /> component.
--}}

@section('title', 'Documentations')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <!-- Header -->
        <h1 class="h4 mb-0">Create new doc</h1>

        <!-- Active Client Selector -->
        <div class="d-flex align-items-center">
            <x-context.selector :clients="$clients" />
        </div>

        <!-- New doc button -->
        <div>
            <a href="{{ route('tech.documentations.create', 'all') }}" class="btn btn-sm btn-primary">New Doc</a>
        </div>
    </div>
@endsection

@section('content')

    @if($selectedTemplate)

        <div class="card mt-4">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="fw-semibold">Document details</span>
                <span class="small text-muted">
                    Category: {{ $selectedCategory->name }} · Template: {{ $selectedTemplate->name }}
                </span>
            </div>
            <div class="card-body">
                <form action="{{ route('tech.documentations.store') }}" method="POST">
                    @csrf
                    <input type="hidden" name="category_id" value="{{ $cat }}">
                    <input type="hidden" name="template_id" value="{{ $selectedTemplate->id }}">
                    <input type="hidden" name="client_id" value="{{ session('active_client_id') }}">
                    <input type="hidden" name="site_id" value="{{ session('active_site_id') }}">

                    @error('template_id')
                        <div class="alert alert-danger py-2">{{ $message }}</div>
                    @enderror

                    <div class="row">
                        <div class="col-md-12 mb-4">
                            <x-forms.input_text Name="title" labelName="Dokument Tittel" value="" />
                        </div>
                    </div>

                    {{--
                        Dynamic form rendering based on the template's field definition.
                        Fields are iterated and rendered using standard form components.
                        The layout property ('rowStart') is used to create section headers.
                    --}}

                    @if(isset($fields))
                        @foreach($fields as $field)
                            @php
                                $fieldName = $field['Name'] ?? null;
                                $isRowStart = isset($field['layout']) && $field['layout'] == "rowStart";
                                $colClass = (isset($field['type']) && $field['type'] == 'textarea') ? 'col-md-12' : 'col-md-3';
                                if ($isRowStart) $colClass = 'col-md-12';
                            @endphp

                            {{-- Handle Section Headers (rowStart) --}}
                            @if($isRowStart)
                                <div class="col-12 mt-4"><hr>@if(isset($field['labelName']))<h6 class="text-primary fw-bold">{{ $field['labelName'] }}</h6>@endif</div>
                            @endif

                            {{-- Render actual input fields (only if they have a 'Name') --}}
                            @if($fieldName)
                                <div class="{{ $colClass }} mb-3">
                                    @if(isset($field['type']) && ($field['type'] == 'text' || $field['type'] == 'date'))
                                        <x-forms.input_text
                                            :Name="$fieldName"
                                            :labelName="$field['labelName'] ?? $fieldName"
                                            :type="$field['type']"
                                            :value="$field['value'] ?? ''"
                                        />
                                    @endif

                                    @if(isset($field['type']) && ($field['type'] == 'checkbox'))
                                        <x-forms.checkbox
                                            :Name="$fieldName"
                                            :id="$fieldName"
                                            :labelName="$field['labelName'] ?? $fieldName"
                                        />
                                    @endif

                                    @if(isset($field['type']) && $field['type'] == 'textarea')
                                        <x-forms.textarea :Name="$fieldName" :labelName="$field['labelName'] ?? $fieldName"></x-forms.textarea>
                                    @endif

                                    @if(isset($field['type']) && $field['type'] == 'select')
                                        <x-forms.select
                                            :name="$fieldName"
                                            :labelName="$field['labelName'] ?? $fieldName">
                                            <option value="">Velg...</option>
                                            @foreach($field['options'] as $optionValue => $optionLabel)
                                                @php
                                                    $val = is_int($optionValue) ? $optionLabel : $optionValue;
                                                    $isSelected = ($field['value'] ?? '') == $val;
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
                    @else
                        <p>No fields</p>
                    @endif

                    <div class="mt-4">
                        <button type="submit" class="btn btn-success">Lagre dokumentasjon</button>
                    </div>
                </form>
            </div>
        </div>
    @elseif($selectedCategory && $templates->count() > 1)
        {{-- Template Selection --}}
        <div class="card mt-3">
            <div class="card-header">
                <span class="fw-semibold">Choose template</span>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    {{ $selectedCategory->name }} has several active templates. Choose the schema for this document.
                </p>

                <form class="row g-3 align-items-end" action="{{ route('tech.documentations.create') }}" method="GET">
                    <input type="hidden" name="cat" value="{{ $selectedCategory->id }}">

                    <div class="col-md-8">
                        <x-forms.select name="template_id" labelName="Template">
                            <option value="">Choose a template...</option>
                            @foreach($templates as $template)
                                <option value="{{ $template->id }}" @selected((string) old('template_id') === (string) $template->id)>
                                    {{ $template->name }}
                                </option>
                            @endforeach
                        </x-forms.select>
                        @error('template_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-4">
                        <button class="btn btn-primary" type="submit">Continue</button>
                    </div>
                </form>
            </div>
        </div>
    @else
        {{-- Fallback: No category/template selected yet. Show the category picker. --}}
        <div class="alert alert-info mt-3">
            <b>Ingen template valgt</b>
            <p>Velg en kategori under for å starte dokumentasjonen.</p>
        </div>

        <div class="row mt-3 mb-3">
            <h2 class="h5 col-12">Choose category</h2>
        </div>

        <form class="row g-3 align-items-end mt-3" action="{{ route('tech.documentations.create') }}" method="GET">

            <div class="col-md-8">
                <x-forms.select name="cat" labelName="Category">
                    <option value="">Choose a category...</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) $cat === (string) $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </x-forms.select>
            </div>

            <div class="col-md-4">
                <button class="btn btn-primary" type="submit">Continue</button>
            </div>

        </form>
    @endif

@endsection

@section('sidebar')
    <x-nav.knowledge-menu />

    <hr class="my-3">

    <x-nav.side-bar :items="$sidebarMenuItems" title="Documentation categories" />
@endsection

@section('rightbar')
@endsection
