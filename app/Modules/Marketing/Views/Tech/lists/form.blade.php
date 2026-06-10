@extends('layouts.default_tech')

@section('title', 'New Marketing List')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="h4 mb-0">New Marketing List</h1>
        <a href="{{ route('tech.marketing.lists.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            Lists
        </a>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Marketing list form -->
    <!-- ------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.marketing.lists.store') }}" class="d-grid gap-3">
        @csrf

        <div class="card">
            <div class="card-header">
                <span class="fw-semibold">List Details</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-8">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $list->name) }}" required maxlength="255" autofocus>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-lg-4">
                        <label for="audience_type" class="form-label">Audience</label>
                        <select id="audience_type" name="audience_type" class="form-select @error('audience_type') is-invalid @enderror" required>
                            <option value="all_business_contacts" @selected(old('audience_type', $list->audience_type) === 'all_business_contacts')>All business contacts</option>
                        </select>
                        @error('audience_type')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-lg-4">
                        <label for="consent_category_id" class="form-label">Consent Category</label>
                        <select id="consent_category_id" name="consent_category_id" class="form-select @error('consent_category_id') is-invalid @enderror">
                            <option value="">General marketing</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected((int) old('consent_category_id', $list->consent_category_id) === $category->id)>
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('consent_category_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-lg-8">
                        <label for="description" class="form-label">Description</label>
                        <textarea id="description" name="description" rows="3" class="form-control @error('description') is-invalid @enderror" maxlength="2000">{{ old('description', $list->description) }}</textarea>
                        @error('description')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="fw-semibold">Segments</span>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="fw-semibold mb-1">Contact Tags</div>
                        <div class="small text-muted mb-2">Only include contacts with at least one selected contact tag.</div>
                        @error('contact_tag_ids')
                            <div class="text-danger small mb-2">{{ $message }}</div>
                        @enderror
                        <div class="border rounded p-2" style="max-height: 220px; overflow-y: auto;">
                            @forelse($tags as $tag)
                                <div class="form-check">
                                    <input type="checkbox" id="contact_tag_{{ $tag->id }}" name="contact_tag_ids[]" value="{{ $tag->id }}" class="form-check-input @error('contact_tag_ids.*') is-invalid @enderror" @checked(in_array($tag->id, old('contact_tag_ids', [])))>
                                    <label for="contact_tag_{{ $tag->id }}" class="form-check-label">
                                        @if($tag->color)
                                            <span class="badge rounded-pill me-1" style="background-color: {{ $tag->color }};">&nbsp;</span>
                                        @endif
                                        {{ $tag->name }}
                                    </label>
                                </div>
                            @empty
                                <div class="text-muted small py-2">No active tags are available.</div>
                            @endforelse
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="fw-semibold mb-1">Client Tags</div>
                        <div class="small text-muted mb-2">Only include contacts connected to clients with at least one selected client tag.</div>
                        @error('client_tag_ids')
                            <div class="text-danger small mb-2">{{ $message }}</div>
                        @enderror
                        <div class="border rounded p-2" style="max-height: 220px; overflow-y: auto;">
                            @forelse($tags as $tag)
                                <div class="form-check">
                                    <input type="checkbox" id="client_tag_{{ $tag->id }}" name="client_tag_ids[]" value="{{ $tag->id }}" class="form-check-input @error('client_tag_ids.*') is-invalid @enderror" @checked(in_array($tag->id, old('client_tag_ids', [])))>
                                    <label for="client_tag_{{ $tag->id }}" class="form-check-label">
                                        @if($tag->color)
                                            <span class="badge rounded-pill me-1" style="background-color: {{ $tag->color }};">&nbsp;</span>
                                        @endif
                                        {{ $tag->name }}
                                    </label>
                                </div>
                            @empty
                                <div class="text-muted small py-2">No active tags are available.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('tech.marketing.lists.index') }}" class="btn btn-sm btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-check2" aria-hidden="true"></i>
                Create List
            </button>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
    <x-card.default title="Resolution Rules">
        <dl class="row small mb-0">
            <dt class="col-7">Consent mode</dt>
            <dd class="col-5 text-end">{{ $settings['consent_mode'] === 'opt_out' ? 'Opt-out' : 'Explicit opt-in' }}</dd>
            <dt class="col-7">Unsubscribe</dt>
            <dd class="col-5 text-end">{{ $settings['unsubscribe_mode'] === 'all_marketing' ? 'All marketing' : 'Category' }}</dd>
            <dt class="col-7">Contract clients</dt>
            <dd class="col-5 text-end">{{ $settings['active_contract_clients_eligible'] ? 'Included' : 'Excluded' }}</dd>
        </dl>
    </x-card.default>
@endsection
