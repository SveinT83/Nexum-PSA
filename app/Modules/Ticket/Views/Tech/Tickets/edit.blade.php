@extends('layouts.default_tech')

@section('title', 'Edit ' . $ticket->ticket_key)

@section('pageName')
    <h3>Tickets</h3>
@endsection

<!-- -------------------------------------------------------------------------------------------------- -->
<!-- Page header -->
<!-- Keeps edit navigation close to the ticket key so technicians know exactly which ticket is being changed. -->
<!-- -------------------------------------------------------------------------------------------------- -->
@section('pageHeader')
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="mb-1">Edit {{ $ticket->ticket_key }}</h1>
            <p class="text-muted mb-0">{{ $ticket->subject }}</p>
        </div>
        <a href="{{ route('tech.tickets.show', $ticket) }}" class="btn btn-light">Back</a>
    </div>
@endsection

@section('content')
<div class="container-fluid px-0">
    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Edit ticket form -->
    <!-- This is the full edit surface for ticket text and lifecycle fields; the show page only summarizes them. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.tickets.update', $ticket) }}">
        @csrf
        @method('PATCH')

        <x-card.default title="Ticket text">
            <div class="mb-3">
                <label for="subject" class="form-label">Subject</label>
                <input id="subject" name="subject" type="text" class="form-control @error('subject') is-invalid @enderror" value="{{ old('subject', $ticket->subject) }}" required>
                @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-0">
                <label for="description" class="form-label">Description</label>
                <textarea id="description" name="description" rows="9" class="form-control @error('description') is-invalid @enderror">{{ old('description', $ticket->description) }}</textarea>
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </x-card.default>

        <x-card.default title="Lifecycle">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="status_id" class="form-label">Status</label>
                    <select id="status_id" name="status_id" class="form-select @error('status_id') is-invalid @enderror">
                        @foreach ($statuses as $status)
                            <option value="{{ $status->id }}" @selected(old('status_id', $ticket->status_id) == $status->id)>{{ $status->name }}</option>
                        @endforeach
                    </select>
                    @error('status_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-3">
                    <label for="queue_id" class="form-label">Queue</label>
                    <select id="queue_id" name="queue_id" class="form-select @error('queue_id') is-invalid @enderror">
                        @foreach ($queues as $queue)
                            <option value="{{ $queue->id }}" @selected(old('queue_id', $ticket->queue_id) == $queue->id)>{{ $queue->name }}</option>
                        @endforeach
                    </select>
                    @error('queue_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-3">
                    <label for="priority_id" class="form-label">Priority</label>
                    <select id="priority_id" name="priority_id" class="form-select @error('priority_id') is-invalid @enderror">
                        @foreach ($priorities as $priority)
                            <option value="{{ $priority->id }}" @selected(old('priority_id', $ticket->priority_id) == $priority->id)>P{{ $priority->level }} {{ $priority->name }}</option>
                        @endforeach
                    </select>
                    @error('priority_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-3">
                    <label for="owner_id" class="form-label">Owner</label>
                    <select id="owner_id" name="owner_id" class="form-select @error('owner_id') is-invalid @enderror">
                        <option value="">Unassigned</option>
                        @foreach ($technicians as $technician)
                            <option value="{{ $technician->id }}" @selected(old('owner_id', $ticket->owner_id) == $technician->id)>{{ $technician->name }}</option>
                        @endforeach
                    </select>
                    @error('owner_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-3">
                    <label for="category_id" class="form-label">Category</label>
                    <select id="category_id" name="category_id" class="form-select @error('category_id') is-invalid @enderror">
                        <option value="">No category</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected(old('category_id', $ticket->category_id) == $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                    @error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </x-card.default>

        <div class="d-flex justify-content-between mt-3">
            <a href="{{ route('tech.tickets.show', $ticket) }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save ticket</button>
        </div>
    </form>
</div>
@endsection

@section('rightbar')
    <x-card.default title="Current details">
        <dl class="mb-0 small">
            <dt>Status</dt>
            <dd>{{ $ticket->status?->name }}</dd>
            <dt>Queue</dt>
            <dd>{{ $ticket->queue?->name }}</dd>
            <dt>Priority</dt>
            <dd>P{{ $ticket->priority?->level }} {{ $ticket->priority?->name }}</dd>
            <dt>Owner</dt>
            <dd class="mb-0">{{ $ticket->owner?->name ?? 'Unassigned' }}</dd>
        </dl>
    </x-card.default>
@endsection
