@extends('customerportal::layouts.portal')

@section('title', 'New Ticket')

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Portal Ticket Create -->
    <!-- ------------------------------------------------- -->
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                <div>
                    <h1 class="h4 mb-1">New ticket</h1>
                    <div class="small text-muted">{{ $context->client->name }}{{ $context->site ? ' - '.$context->site->name : '' }}</div>
                </div>
                <a href="{{ route('customer-portal.tickets.index') }}" class="btn btn-outline-secondary">Back</a>
            </div>

            <div class="card shadow-sm">
                <div class="card-body">
                    <form method="POST" action="{{ route('customer-portal.tickets.store') }}">
                        @csrf

                        @if(! $context->site && $sites->count() > 1)
                            <div class="mb-3">
                                <label for="site_id" class="form-label">Site</label>
                                <select id="site_id" name="site_id" class="form-select @error('site_id') is-invalid @enderror">
                                    <option value="">Default site</option>
                                    @foreach($sites as $site)
                                        <option value="{{ $site->id }}" @selected((string) old('site_id') === (string) $site->id)>{{ $site->name }}</option>
                                    @endforeach
                                </select>
                                @error('site_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        @endif

                        <div class="mb-3">
                            <label for="subject" class="form-label">Subject</label>
                            <input type="text" id="subject" name="subject" value="{{ old('subject') }}" class="form-control @error('subject') is-invalid @enderror" required maxlength="255">
                            @error('subject') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Message</label>
                            <textarea id="description" name="description" rows="8" class="form-control @error('description') is-invalid @enderror" required>{{ old('description') }}</textarea>
                            @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <a href="{{ route('customer-portal.tickets.index') }}" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-send me-1" aria-hidden="true"></i>
                                Create ticket
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
