@extends('customerportal::layouts.portal')

@section('title', $documentation->title)

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Portal Document Detail -->
    <!-- ------------------------------------------------- -->
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ $documentation->title }}</h1>
            <div class="small text-muted">
                {{ $documentation->category?->name ?: 'Document' }}
                @if($documentation->site)
                    &middot; {{ $documentation->site->name }}
                @endif
            </div>
        </div>
        <a href="{{ route('customer-portal.documents.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
            Documents
        </a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="row g-3">
                @php
                    $fields = $documentation->template_snapshot_json ?? [];
                    $data = $documentation->data_json ?? [];
                @endphp

                @forelse($fields as $field)
                    @php
                        $fieldName = $field['Name'] ?? null;
                        $value = $fieldName ? ($data[$fieldName] ?? '-') : null;

                        if (($field['type'] ?? null) === 'checkbox') {
                            $value = ($value && $value !== 'false' && $value !== '0') ? 'Yes' : 'No';
                        }

                        if (($field['type'] ?? null) === 'select' && isset($field['options'])) {
                            foreach($field['options'] as $optionValue => $optionLabel) {
                                $candidateValue = is_int($optionValue) ? $optionLabel : $optionValue;
                                if ($value == $candidateValue) {
                                    $value = $optionLabel;
                                    break;
                                }
                            }
                        }

                        $isSectionHeader = ($field['layout'] ?? null) === 'rowStart';
                        $colClass = (($field['type'] ?? null) === 'textarea' || $isSectionHeader) ? 'col-12' : 'col-md-6 col-lg-4';
                    @endphp

                    @if($isSectionHeader)
                        <div class="col-12">
                            <hr class="my-2">
                            @if(isset($field['labelName']))
                                <h2 class="h6 text-muted mb-2">{{ $field['labelName'] }}</h2>
                            @endif
                        </div>
                    @endif

                    @if($fieldName)
                        <div class="{{ $colClass }}">
                            <div class="small text-muted mb-1">{{ $field['labelName'] ?? $fieldName }}</div>
                            <div class="border rounded bg-light p-2" style="white-space: pre-wrap;">{{ $value }}</div>
                        </div>
                    @endif
                @empty
                    <div class="col-12 text-muted">This document has no visible fields.</div>
                @endforelse
            </div>
        </div>
        <div class="card-footer text-muted small">
            Updated {{ $documentation->updated_at?->format('Y-m-d H:i') }}
        </div>
    </div>
@endsection
