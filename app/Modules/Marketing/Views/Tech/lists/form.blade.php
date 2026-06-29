@extends('layouts.default_tech')

@section('title', $list->exists ? 'Edit Marketing List' : 'New Marketing List')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="h4 mb-0">{{ $list->exists ? 'Edit Marketing List' : 'New Marketing List' }}</h1>
        <a href="{{ $list->exists ? route('tech.marketing.lists.show', $list) : route('tech.marketing.lists.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            {{ $list->exists ? 'List' : 'Lists' }}
        </a>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Marketing list form -->
    <!-- ------------------------------------------------- -->
    @if($errors->any())
        <div class="alert alert-danger py-2">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ $list->exists ? route('tech.marketing.lists.update', $list) : route('tech.marketing.lists.store') }}" class="d-grid gap-3">
        @csrf
        @if($list->exists)
            @method('PUT')
        @endif

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
                    $criteria = $list->segment_criteria ?? [];
                    $selectedManualContacts = collect(old('manual_contact_ids', $criteria['manual_contact_ids'] ?? []))
                        ->map(fn ($id) => (int) $id)
                        ->filter()
                        ->all();
                    $selectedManualClientUsers = collect(old('manual_client_user_ids', $criteria['manual_client_user_ids'] ?? []))
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
                @error('manual_client_user_ids')
                    <div class="text-danger small mb-2">{{ $message }}</div>
                @enderror
                @error('manual_client_user_ids.*')
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

                @if($manualClientUsers->isNotEmpty())
                    <div class="small text-muted text-uppercase fw-semibold mt-3 mb-2">Client contacts pending Contact migration</div>
                    <div class="border rounded" style="max-height: 260px; overflow-y: auto;">
                        @foreach($manualClientUsers as $clientUser)
                            @php
                                $searchText = strtolower(trim($clientUser->name.' '.$clientUser->email.' '.$clientUser->site?->client?->name.' '.$clientUser->site?->name));
                            @endphp
                            <label class="d-flex align-items-start gap-2 px-3 py-2 border-bottom mb-0 manual-contact-row" data-search="{{ $searchText }}">
                                <input
                                    type="checkbox"
                                    name="manual_client_user_ids[]"
                                    value="{{ $clientUser->id }}"
                                    class="form-check-input mt-1 @error('manual_client_user_ids.*') is-invalid @enderror"
                                    @checked(in_array($clientUser->id, $selectedManualClientUsers, true))>
                                <span>
                                    <span class="fw-semibold d-block">{{ $clientUser->name }}</span>
                                    <span class="small text-muted">
                                        {{ $clientUser->email }}
                                        @if($clientUser->site?->client)
                                            · {{ $clientUser->site->client->name }}
                                        @endif
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="fw-semibold">Segments</span>
            </div>
            <div class="card-body">
                @php
                    $selectedSalesCategoryIds = collect(old('sales_category_ids', $criteria['sales_category_ids'] ?? []))
                        ->map(fn ($id) => (int) $id)
                        ->filter()
                        ->all();
                    $contractFilter = old('contract_filter', $criteria['contract_filter'] ?? 'any');
                    $postalCodes = collect((array) old('postal_codes', $criteria['postal_codes'] ?? []))->implode(', ');
                    $counties = collect((array) old('counties', $criteria['counties'] ?? []))->implode(', ');
                    $countries = collect((array) old('countries', $criteria['countries'] ?? []))->implode(', ');
                @endphp
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
                                    <input type="checkbox" id="contact_tag_{{ $tag->id }}" name="contact_tag_ids[]" value="{{ $tag->id }}" class="form-check-input @error('contact_tag_ids.*') is-invalid @enderror" @checked(in_array($tag->id, collect(old('contact_tag_ids', $criteria['contact_tag_ids'] ?? []))->map(fn ($id) => (int) $id)->all(), true))>
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
                                    <input type="checkbox" id="client_tag_{{ $tag->id }}" name="client_tag_ids[]" value="{{ $tag->id }}" class="form-check-input @error('client_tag_ids.*') is-invalid @enderror" @checked(in_array($tag->id, collect(old('client_tag_ids', $criteria['client_tag_ids'] ?? []))->map(fn ($id) => (int) $id)->all(), true))>
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
                    <div class="col-lg-6">
                        <div class="fw-semibold mb-1">Client Profile</div>
                        <div class="small text-muted mb-2">Filter by client industry and contract status.</div>
                        @error('sales_category_ids')
                            <div class="text-danger small mb-2">{{ $message }}</div>
                        @enderror
                        <div class="border rounded p-2 mb-3" style="max-height: 220px; overflow-y: auto;">
                            @forelse($salesCategories as $category)
                                <div class="form-check">
                                    <input type="checkbox" id="sales_category_{{ $category->id }}" name="sales_category_ids[]" value="{{ $category->id }}" class="form-check-input @error('sales_category_ids.*') is-invalid @enderror" @checked(in_array($category->id, $selectedSalesCategoryIds, true))>
                                    <label for="sales_category_{{ $category->id }}" class="form-check-label">{{ $category->name }}</label>
                                </div>
                            @empty
                                <div class="text-muted small py-2">No active client categories are available.</div>
                            @endforelse
                        </div>
                        <label for="contract_filter" class="form-label">Contract Status</label>
                        <select id="contract_filter" name="contract_filter" class="form-select @error('contract_filter') is-invalid @enderror">
                            <option value="any" @selected($contractFilter === 'any')>Any contract status</option>
                            <option value="with_contract" @selected($contractFilter === 'with_contract')>Has any contract</option>
                            <option value="without_contract" @selected($contractFilter === 'without_contract')>Has no contracts</option>
                            <option value="active_contract" @selected($contractFilter === 'active_contract')>Has active approved/won contract</option>
                            <option value="without_active_contract" @selected($contractFilter === 'without_active_contract')>No active approved/won contract</option>
                            <option value="won_contract" @selected($contractFilter === 'won_contract')>Has won contract</option>
                        </select>
                        @error('contract_filter')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-lg-6">
                        <div class="fw-semibold mb-1">Location</div>
                        <div class="small text-muted mb-2">Comma separated values. Matches contact, client user, and site location fields.</div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="postal_codes" class="form-label">Postcodes</label>
                                <input type="text" id="postal_codes" name="postal_codes" class="form-control @error('postal_codes') is-invalid @enderror" value="{{ $postalCodes }}">
                                @error('postal_codes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label for="counties" class="form-label">Counties</label>
                                <input type="text" id="counties" name="counties" class="form-control @error('counties') is-invalid @enderror" value="{{ $counties }}">
                                @error('counties')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label for="countries" class="form-label">Countries</label>
                                <input type="text" id="countries" name="countries" class="form-control @error('countries') is-invalid @enderror" value="{{ $countries }}">
                                @error('countries')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ $list->exists ? route('tech.marketing.lists.show', $list) : route('tech.marketing.lists.index') }}" class="btn btn-sm btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-check2" aria-hidden="true"></i>
                {{ $list->exists ? 'Save List' : 'Create List' }}
            </button>
        </div>
    </form>

    @if($list->exists)
        <div class="card border-danger mt-3">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <span class="fw-semibold text-danger">Danger Zone</span>
                <span class="badge text-bg-light border">{{ $list->campaign_usage_count ?? 0 }} campaigns</span>
            </div>
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="small text-muted">
                    @if(($list->campaign_usage_count ?? 0) > 0)
                        This list is used by campaigns and cannot be deleted without preserving campaign history first.
                    @else
                        Delete this list and its resolved recipients. Contacts are not deleted.
                    @endif
                </div>
                <form method="POST" action="{{ route('tech.marketing.lists.destroy', $list) }}" class="mb-0" onsubmit="return confirm('Delete this marketing list?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-outline-danger" @disabled(($list->campaign_usage_count ?? 0) > 0)>
                        <i class="bi bi-trash" aria-hidden="true"></i>
                        Delete List
                    </button>
                </form>
            </div>
        </div>
    @endif
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
