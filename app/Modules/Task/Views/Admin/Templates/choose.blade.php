@extends('layouts.default_tech')

@section('title', 'Apply Task template')

@section('pageHeader')
    <h1 class="h4 mb-0">Apply Task template</h1>
@endsection

@section('content')
    <!-- Owner context and current templates -->
    <div class="card">
        <div class="card-header">
            <span class="fw-semibold">Choose a template for {{ class_basename($owner) }} #{{ $ownerId }}</span>
        </div>
        <div class="list-group list-group-flush">
            @forelse($templates as $template)
                <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-3"
                   href="{{ route('tech.task-templates.preview', ['template' => $template, 'owner_type' => $ownerType, 'owner_id' => $ownerId]) }}">
                    <span><span class="fw-semibold d-block">{{ $template->name }}</span><span class="small text-muted">{{ $template->description ?: 'No description.' }}</span></span>
                    <span class="badge text-bg-light border">{{ $template->all_items_count }} Tasks</span>
                </a>
            @empty
                <div class="list-group-item text-muted">No active Task templates are available.</div>
            @endforelse
        </div>
    </div>
@endsection
