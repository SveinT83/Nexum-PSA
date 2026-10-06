@extends('layouts.default_tech')

@section('title', $template->exists ? 'Edit Task Template' : 'New Task Template')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1 class="h4 mb-0">{{ $template->exists ? 'Edit Task Template' : 'New Task Template' }}</h1>
        <x-buttons.back :url="$template->exists ? route('tech.admin.task-templates.show', $template) : route('tech.admin.task-templates.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <form method="POST" action="{{ $template->exists ? route('tech.admin.task-templates.update', $template) : route('tech.admin.task-templates.store') }}">
        @csrf
        @if($template->exists) @method('PUT') @endif
        <div class="card">
            <div class="card-header"><h2 class="h6 mb-0">Template details</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-8"><label class="form-label" for="name">Name</label><input class="form-control" id="name" name="name" required value="{{ old('name', $template->name) }}">@error('name')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                    <div class="col-md-4"><label class="form-label" for="slug">Key</label><input class="form-control" id="slug" name="slug" value="{{ old('slug', $template->slug) }}" placeholder="Generated from name"></div>
                    <div class="col-12"><label class="form-label" for="description">Description</label><textarea class="form-control" id="description" name="description" rows="3">{{ old('description', $template->description) }}</textarea></div>
                    <div class="col-12"><div class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $template->exists ? $template->is_active : true))><label class="form-check-label" for="is_active">Active for future use</label></div></div>
                </div>
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary">Save template</button></div>
        </div>
    </form>
@endsection
