@extends('layouts.default_tech')

@section('title', 'Preview Task Template')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2"><h1 class="h4 mb-0">Preview {{ $template->name }}</h1><x-buttons.back :url="route('tech.admin.task-templates.show', $template)" class="mb-0">Back</x-buttons.back></div>
@endsection

@section('content')
    <div class="card mb-3"><div class="card-header"><h2 class="h6 mb-0">Tasks to create</h2></div><div class="list-group list-group-flush">@foreach($template->allItems as $item)<div class="list-group-item"><div class="fw-semibold" style="padding-left: {{ $item->parent_id ? '1.5rem' : '0' }}">{{ $item->title }}</div><div class="small text-muted">{{ $item->checklistItems->count() }} checklist items · {{ $item->dependencies->count() }} dependencies · {{ $item->estimated_minutes ? $item->estimated_minutes.' minutes' : 'No estimate' }}</div></div>@endforeach</div></div>
    <form method="POST" action="{{ route('tech.task-templates.apply', $template) }}" class="card"><div class="card-body">@csrf<input type="hidden" name="owner_type" value="{{ $ownerType }}"><input type="hidden" name="owner_id" value="{{ $ownerId }}"><input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}"><p class="mb-3">Create {{ $template->allItems->count() }} Tasks for <strong>{{ $owner->name ?? $owner->ticket_key ?? ('#'.$ownerId) }}</strong>. Existing Tasks will not be changed when this template is edited later.</p><div class="row g-2"><div class="col-md-6"><label class="form-label" for="anchor_at">Date anchor</label><input class="form-control" type="datetime-local" id="anchor_at" name="anchor_at" value="{{ now()->format('Y-m-d\TH:i') }}"></div></div></div><div class="card-footer text-end"><button class="btn btn-primary">Create Tasks</button></div></form>
@endsection
