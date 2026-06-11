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
                            <option value="manual_contacts" @selected(old('audience_type', $list->audience_type) === 'manual_contacts')>Manual contacts only</option>
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
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <span class="fw-semibold">Manual Contacts</span>
                <span class="badge text-bg-light border">{{ $manualContacts->count() }} available</span>
            </div>
            <div class="card-body">
                @php
                    $selectedManualContacts = collect(old('manual_contact_ids', []))
                        ->map(fn ($id) => (int) $id)
                        ->filter()
                        ->all();
                @endphp

                @error('manual_contact_ids')
                    <div class="text-danger small mb-2">{{ $message }}</div>
                @enderror
                @error('manual_contact_ids.*')
                    <div class="text-danger small mb-2">{{ $message }}</div>
                @enderror

                <div class="row g-3">
                    <div class="col-lg-5">
                        <label for="manual_contact_filter" class="form-label">Search Contacts</label>
                        <input type="search" id="manual_contact_filter" class="form-control" placeholder="Name or email">
                    </div>
                    <div class="col-lg-7">
                        <div class="small text-muted mt-lg-4 pt-lg-2">
                            Manual contacts are added in addition to automatic segments. With Manual contacts only, only selected contacts are resolved.
                        </div>
                    </div>
                </div>

                <div class="border rounded mt-3" style="max-height: 320px; overflow-y: auto;">
                    @forelse($manualContacts as $contact)
                        @php
                            $email = $contact->emails->firstWhere('is_primary', true) ?? $contact->emails->first();
                            $searchText = strtolower(trim($contact->display_name.' '.$email?->email));
                        @endphp
                        <label class="d-flex align-items-start gap-2 px-3 py-2 border-bottom mb-0 manual-contact-row" data-search="{{ $searchText }}">
                            <input
                                type="checkbox"
                                name="manual_contact_ids[]"
                                value="{{ $contact->id }}"
                                class="form-check-input mt-1 @error('manual_contact_ids.*') is-invalid @enderror"
                                @checked(in_array($contact->id, $selectedManualContacts, true))>
                            <span>
                                <span class="fw-semibold d-block">{{ $contact->display_name }}</span>
                                <span class="small text-muted">{{ $email?->email }}</span>
                            </span>
                        </label>
                    @empty
                        <div class="text-muted small p-3">No active contacts with email are available for manual selection.</div>
                    @endforelse
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

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const filter = document.getElementById('manual_contact_filter');
            const rows = Array.from(document.querySelectorAll('.manual-contact-row'));

            if (!filter || rows.length === 0) {
                return;
            }

            filter.addEventListener('input', () => {
                const needle = filter.value.trim().toLowerCase();

                rows.forEach((row) => {
                    row.classList.toggle('d-none', needle !== '' && !row.dataset.search.includes(needle));
                });
            });
        });
    </script>
@endsection
