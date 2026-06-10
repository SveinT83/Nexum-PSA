@extends('layouts.default_tech')

@section('title', 'New Marketing Campaign')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="h4 mb-0">New Marketing Campaign</h1>
        <a href="{{ route('tech.marketing.campaigns.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            Campaigns
        </a>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Marketing campaign form -->
    <!-- ------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.marketing.campaigns.store') }}" class="d-grid gap-3">
        @csrf

        <div class="card">
            <div class="card-header">
                <span class="fw-semibold">Campaign</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-8">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required maxlength="255" autofocus>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-4">
                        <label for="marketing_list_id" class="form-label">List</label>
                        <select id="marketing_list_id" name="marketing_list_id" class="form-select @error('marketing_list_id') is-invalid @enderror" required>
                            <option value="">Select list</option>
                            @foreach($lists as $list)
                                <option value="{{ $list->id }}" @selected((int) old('marketing_list_id') === $list->id)>{{ $list->name }} ({{ $list->members_count }})</option>
                            @endforeach
                        </select>
                        @error('marketing_list_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-8">
                        <label for="description" class="form-label">Description</label>
                        <textarea id="description" name="description" rows="3" class="form-control @error('description') is-invalid @enderror" maxlength="2000">{{ old('description') }}</textarea>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-4">
                        <label for="starts_at" class="form-label">Start</label>
                        <input type="datetime-local" id="starts_at" name="starts_at" class="form-control @error('starts_at') is-invalid @enderror" value="{{ old('starts_at') }}">
                        @error('starts_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="fw-semibold">First Email</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <label for="email_template_id" class="form-label">Template</label>
                        <select id="email_template_id" name="email_template_id" class="form-select @error('email_template_id') is-invalid @enderror" required>
                            <option value="">Select template</option>
                            @foreach($templates as $template)
                                <option value="{{ $template->id }}" @selected((int) old('email_template_id') === $template->id)>{{ $template->name }}</option>
                            @endforeach
                        </select>
                        @error('email_template_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-6">
                        <label for="subject_override" class="form-label">Subject Override</label>
                        <input type="text" id="subject_override" name="subject_override" class="form-control @error('subject_override') is-invalid @enderror" value="{{ old('subject_override') }}" maxlength="255">
                        @error('subject_override')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-6">
                        <label for="email_account_id" class="form-label">Sender Account</label>
                        <select id="email_account_id" name="email_account_id" class="form-select @error('email_account_id') is-invalid @enderror">
                            <option value="">Marketing default</option>
                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}" @selected((int) old('email_account_id') === $account->id)>{{ $account->address }}</option>
                            @endforeach
                        </select>
                        @error('email_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-3">
                        <label for="batch_size" class="form-label">Batch Size</label>
                        <input type="number" min="1" max="1000" id="batch_size" name="batch_size" class="form-control @error('batch_size') is-invalid @enderror" value="{{ old('batch_size', $settings['default_batch_size']) }}">
                        @error('batch_size')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-3">
                        <label for="send_interval_minutes" class="form-label">Interval Minutes</label>
                        <input type="number" min="1" max="1440" id="send_interval_minutes" name="send_interval_minutes" class="form-control @error('send_interval_minutes') is-invalid @enderror" value="{{ old('send_interval_minutes', $settings['default_send_interval_minutes']) }}">
                        @error('send_interval_minutes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-6">
                        <input type="hidden" name="track_opens" value="0">
                        <div class="form-check">
                            <input type="checkbox" id="track_opens" name="track_opens" value="1" class="form-check-input" @checked(old('track_opens', $campaign->track_opens))>
                            <label for="track_opens" class="form-check-label">Track opens</label>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <input type="hidden" name="track_clicks" value="0">
                        <div class="form-check">
                            <input type="checkbox" id="track_clicks" name="track_clicks" value="1" class="form-check-input" @checked(old('track_clicks', $campaign->track_clicks))>
                            <label for="track_clicks" class="form-check-label">Track clicks</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('tech.marketing.campaigns.index') }}" class="btn btn-sm btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-check2" aria-hidden="true"></i>
                Create Draft
            </button>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
    <x-card.default title="Approval">
        <div class="small text-muted">
            Campaigns are saved as drafts. A technician with campaign approval permission must approve before the queue sends due recipients.
        </div>
    </x-card.default>
@endsection
