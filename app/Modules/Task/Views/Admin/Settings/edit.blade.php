@extends('layouts.default_tech')

@section('title', 'Task Settings')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1>Task Settings</h1>
        <x-buttons.back :url="route('tech.admin.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Task Settings Form -->
    <!-- ------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.admin.settings.tasks.update') }}">
        @csrf
        @method('PUT')

        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0">Manual Task Defaults</h2>
            </div>
            <div class="card-body">
                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif

                <div class="row g-3">
                    <div class="col-lg-4">
                        <label for="default_status_id" class="form-label">Default status</label>
                        <select id="default_status_id" name="default_status_id" class="form-select @error('default_status_id') is-invalid @enderror">
                            @foreach($statuses as $status)
                                <option value="{{ $status->id }}" @selected((int) old('default_status_id', $defaultStatusId) === $status->id)>
                                    {{ $status->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('default_status_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-lg-4">
                        <label for="default_priority_id" class="form-label">Default priority</label>
                        <select id="default_priority_id" name="default_priority_id" class="form-select @error('default_priority_id') is-invalid @enderror">
                            <option value="">No priority</option>
                            @foreach($priorities as $priority)
                                <option value="{{ $priority->id }}" @selected((string) old('default_priority_id', $settings['default_priority_id']) === (string) $priority->id)>
                                    {{ $priority->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('default_priority_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-lg-4">
                        <label for="default_estimated_minutes" class="form-label">Default estimate minutes</label>
                        <input
                            type="number"
                            min="1"
                            max="10080"
                            class="form-control @error('default_estimated_minutes') is-invalid @enderror"
                            id="default_estimated_minutes"
                            name="default_estimated_minutes"
                            value="{{ old('default_estimated_minutes', $settings['default_estimated_minutes']) }}"
                            placeholder="No default"
                        >
                        @error('default_estimated_minutes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="{{ route('tech.admin.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
            </div>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="tasks" />
@endsection

@section('rightbar')
    <!-- ------------------------------------------------- -->
    <!-- Settings Help -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header">
            <h2 class="h6 mb-0">Documentation / Help</h2>
        </div>
        <div class="card-body small">
            <p>
                These defaults are applied when a technician creates a manual task. Ticket-owned tasks can still inherit ticket queue, priority, category, assignee, and tags.
            </p>
            <p class="mb-0">
                Task statuses remain table-driven so later workflow and template features can reuse the same status catalog.
            </p>
        </div>
    </div>
@endsection
