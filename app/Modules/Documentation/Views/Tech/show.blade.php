@extends('layouts.default_tech')

{{--
    Documentation View Page (Rendered)

    This view displays the stored documentation data in a read-only format.
    It dynamically maps fields from 'template_snapshot_json' to values in 'data_json'.
    The layout and styling match the creation/edit forms for visual consistency.
--}}

@section('title', $documentation->title)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <div>
            <h1 class="h4 mb-0">{{ $documentation->title }}</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tech.documentations.index') }}">Documentations</a></li>
                    <li class="breadcrumb-item active">{{ $documentation->category->name }}</li>
                </ol>
            </nav>
        </div>

        <div class="d-flex gap-2">
            <a href="{{ route('tech.documentations.edit', $documentation->id) }}" class="btn btn-sm btn-outline-primary d-flex align-items-center px-3">
                <i class="bi bi-pencil me-2"></i> Edit
            </a>
            <form action="{{ route('tech.documentations.destroy', $documentation->id) }}" method="POST" onsubmit="return confirm('Er du sikker på at du vil slette denne dokumentasjonen?');" class="d-inline">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger d-flex align-items-center px-3">
                    <i class="bi bi-trash me-2"></i> Slett
                </button>
            </form>
            <span class="badge bg-secondary d-flex align-items-center px-3">
                Scope: {{ ucfirst($documentation->scope_type) }}
            </span>
            @if($documentation->client)
                <span class="badge bg-info d-flex align-items-center px-3">
                    Client: {{ $documentation->client->name }}
                </span>
            @endif
            @if($documentation->site)
                <span class="badge bg-info d-flex align-items-center px-3">
                    Site: {{ $documentation->site->name }}
                </span>
            @endif
        </div>
    </div>
@endsection

@section('content')
    <div class="card mt-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Documentation Details</h5>
        </div>
        <div class="card-body">
            <div class="row">
                @php
                    $fields = $documentation->template_snapshot_json ?? [];
                    $data = $documentation->data_json ?? [];
                @endphp

                @foreach($fields as $field)
                    @php
                        $fieldName = $field['Name'] ?? null;
                        $value = $data[$fieldName] ?? '-';

                        // Data transformation for display (e.g., Boolean, Select labels)

                        // Handle checkbox boolean
                        if (isset($field['type']) && $field['type'] == 'checkbox') {
                            $value = ($value && $value !== 'false' && $value !== '0') ? 'Yes' : 'No';
                        }

                        // Handle select value vs label
                        if (isset($field['type']) && $field['type'] == 'select' && isset($field['options'])) {
                             foreach($field['options'] as $optionValue => $optionLabel) {
                                 $val = is_int($optionValue) ? $optionLabel : $optionValue;
                                 if ($value == $val) {
                                     $value = $optionLabel;
                                     break;
                                 }
                             }
                        }

                        // Layout handling logic
                        $isRowStart = isset($field['layout']) && $field['layout'] == "rowStart";
                        $isRowEnd = isset($field['layout']) && $field['layout'] == "rowEnd";
                        $colClass = (isset($field['type']) && $field['type'] == 'textarea') ? 'col-md-12' : 'col-md-4';

                        if ($isRowStart) {
                             $colClass = 'col-md-12';
                        }
                    @endphp

                    {{-- Render Section Headers --}}
                    @if($isRowStart)
                         <div class="col-12 mt-4"><hr>@if(isset($field['labelName']))<h6 class="text-primary fw-bold">{{ $field['labelName'] }}</h6>@endif</div>
                    @endif

                    {{-- Render Field Data Labels/Values --}}
                    @if($fieldName)
                        <div class="{{ $colClass }} mb-3">
                            <label class="form-label text-muted small fw-bold mb-1">{{ $field['labelName'] ?? $fieldName }}</label>
                            <div class="p-2 bg-light border rounded" style="min-height: 38px; white-space: pre-wrap;">{{ $value }}</div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
        <div class="card-footer text-muted small">
            Created: {{ $documentation->created_at->format('d.m.Y H:i') }} |
            Template: {{ $documentation->template->name ?? 'N/A' }}
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.knowledge-menu />

    <hr class="my-3">

    <x-nav.side-bar :items="$sidebarMenuItems" title="Documentation categories" />
@endsection
