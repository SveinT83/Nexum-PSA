@extends('layouts.default_tech')

@section('title', $list->name)

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="h4 mb-0">{{ $list->name }}</h1>
            <div class="small text-muted">{{ $list->description ?: 'Marketing recipient list' }}</div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('tech.marketing.lists.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                Lists
            </a>
            @can('marketing.list.manage')
                <a href="{{ route('tech.marketing.lists.edit', $list) }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                    Edit
                </a>
                <form method="POST" action="{{ route('tech.marketing.lists.refresh', $list) }}" class="mb-0">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                        Refresh
                    </button>
                </form>
            @endcan
        </div>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Marketing list members -->
    <!-- ------------------------------------------------- -->
    @if(session('status'))
        <div class="alert alert-success py-2">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger py-2">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="small text-muted text-uppercase fw-semibold">Members</div>
                    <div class="fs-4 fw-semibold">{{ $list->members_count }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="small text-muted text-uppercase fw-semibold">Audience</div>
                    <div class="fw-semibold">{{ str($list->audience_type)->replace('_', ' ')->title() }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="small text-muted text-uppercase fw-semibold">Last Resolved</div>
                    <div class="fw-semibold">{{ $list->last_resolved_at?->format('Y-m-d H:i') ?? '—' }}</div>
                </div>
            </div>
        </div>
    </div>

    @php
        $criteria = $list->segment_criteria ?? [];
        $contactTagIds = collect($criteria['contact_tag_ids'] ?? [])->map(fn ($id) => (int) $id)->filter();
        $clientTagIds = collect($criteria['client_tag_ids'] ?? [])->map(fn ($id) => (int) $id)->filter();
        $manualContactIds = collect($criteria['manual_contact_ids'] ?? [])->map(fn ($id) => (int) $id)->filter();
        $manualClientUserIds = collect($criteria['manual_client_user_ids'] ?? [])->map(fn ($id) => (int) $id)->filter();
        $salesCategoryIds = collect($criteria['sales_category_ids'] ?? [])->map(fn ($id) => (int) $id)->filter();
        $postalCodes = collect($criteria['postal_codes'] ?? [])->filter();
        $counties = collect($criteria['counties'] ?? [])->filter();
        $countries = collect($criteria['countries'] ?? [])->filter();
        $contractFilter = $criteria['contract_filter'] ?? 'any';
        $excludedContactIds = collect($criteria['excluded_contact_ids'] ?? [])->map(fn ($id) => (int) $id)->filter();
    @endphp

    @if($contactTagIds->isNotEmpty() || $clientTagIds->isNotEmpty() || $manualContactIds->isNotEmpty() || $manualClientUserIds->isNotEmpty() || $salesCategoryIds->isNotEmpty() || $postalCodes->isNotEmpty() || $counties->isNotEmpty() || $countries->isNotEmpty() || $contractFilter !== 'any' || $excludedContactIds->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header">
                <span class="fw-semibold">Active Segments</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @if($manualContactIds->isNotEmpty())
                        <div class="col-md-4">
                            <div class="small text-muted text-uppercase fw-semibold mb-2">Manual contacts</div>
                            <span class="badge text-bg-light border">{{ $manualContactIds->count() }} selected</span>
                        </div>
                    @endif
                    @if($manualClientUserIds->isNotEmpty())
                        <div class="col-md-4">
                            <div class="small text-muted text-uppercase fw-semibold mb-2">Client contacts</div>
                            <span class="badge text-bg-light border">{{ $manualClientUserIds->count() }} selected</span>
                        </div>
                    @endif
                    @if($excludedContactIds->isNotEmpty())
                        <div class="col-md-4">
                            <div class="small text-muted text-uppercase fw-semibold mb-2">Excluded contacts</div>
                            <span class="badge text-bg-light border">{{ $excludedContactIds->count() }} removed</span>
                        </div>
                    @endif
                    @if($contactTagIds->isNotEmpty())
                        <div class="col-md-4">
                            <div class="small text-muted text-uppercase fw-semibold mb-2">Contact tags</div>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach($contactTagIds as $tagId)
                                    @if($segmentTags->has($tagId))
                                        <span class="badge text-bg-light border">{{ $segmentTags->get($tagId)->name }}</span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                    @if($clientTagIds->isNotEmpty())
                        <div class="col-md-4">
                            <div class="small text-muted text-uppercase fw-semibold mb-2">Client tags</div>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach($clientTagIds as $tagId)
                                    @if($segmentTags->has($tagId))
                                        <span class="badge text-bg-light border">{{ $segmentTags->get($tagId)->name }}</span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                    @if($salesCategoryIds->isNotEmpty())
                        <div class="col-md-4">
                            <div class="small text-muted text-uppercase fw-semibold mb-2">Client industry</div>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach($salesCategoryIds as $categoryId)
                                    @if($salesCategories->has($categoryId))
                                        <span class="badge text-bg-light border">{{ $salesCategories->get($categoryId)->name }}</span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                    @if($contractFilter !== 'any')
                        <div class="col-md-4">
                            <div class="small text-muted text-uppercase fw-semibold mb-2">Contract status</div>
                            <span class="badge text-bg-light border">{{ str($contractFilter)->replace('_', ' ')->title() }}</span>
                        </div>
                    @endif
                    @if($postalCodes->isNotEmpty() || $counties->isNotEmpty() || $countries->isNotEmpty())
                        <div class="col-md-4">
                            <div class="small text-muted text-uppercase fw-semibold mb-2">Location</div>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach($postalCodes as $value)
                                    <span class="badge text-bg-light border">Postcode {{ $value }}</span>
                                @endforeach
                                @foreach($counties as $value)
                                    <span class="badge text-bg-light border">{{ $value }}</span>
                                @endforeach
                                @foreach($countries as $value)
                                    <span class="badge text-bg-light border">{{ $value }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @can('marketing.list.manage')
        <div class="d-flex justify-content-end mb-2">
            <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#addContactsPanel" aria-expanded="false" aria-controls="addContactsPanel">
                <i class="bi bi-person-plus" aria-hidden="true"></i>
                Add Contacts
            </button>
        </div>

        <div class="collapse mb-3" id="addContactsPanel">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                    <span class="fw-semibold">Add Contacts</span>
                    <span class="badge text-bg-light border">{{ $addableContacts->count() }} available</span>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('tech.marketing.lists.contacts.add', $list) }}">
                        @csrf
                        <div class="row g-3">
                            <div class="col-lg-5">
                                <label for="add_contact_filter" class="form-label">Search Contacts</label>
                                <input type="search" id="add_contact_filter" class="form-control form-control-sm" placeholder="Name or email">
                            </div>
                            <div class="col-lg-7">
                                <div class="small text-muted mt-lg-4 pt-lg-1">
                                    Added contacts are stored in the list criteria, then the list is refreshed.
                                </div>
                            </div>
                        </div>

                        <div class="border rounded mt-3" style="max-height: 260px; overflow-y: auto;">
                            @forelse($addableContacts as $contact)
                                @php
                                    $email = $contact->emails->firstWhere('is_primary', true) ?? $contact->emails->first();
                                    $searchText = strtolower(trim($contact->display_name.' '.$email?->email));
                                @endphp
                                <label class="d-flex align-items-start gap-2 px-3 py-2 border-bottom mb-0 add-contact-row" data-search="{{ $searchText }}">
                                    <input type="checkbox" name="contact_ids[]" value="{{ $contact->id }}" class="form-check-input mt-1">
                                    <span>
                                        <span class="fw-semibold d-block">{{ $contact->display_name }}</span>
                                        <span class="small text-muted">{{ $email?->email }}</span>
                                    </span>
                                </label>
                            @empty
                                <div class="text-muted small p-3">No additional eligible contacts are available.</div>
                            @endforelse
                        </div>

                        <div class="text-end mt-3">
                            <button type="submit" class="btn btn-sm btn-primary" @disabled($addableContacts->isEmpty())>
                                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                Add Selected
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan

    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <span class="fw-semibold">Recipients</span>
            <span class="badge text-bg-light border">{{ $members->total() }} resolved</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Client</th>
                        <th>Source</th>
                        <th>Status</th>
                        @can('marketing.list.manage')
                            <th class="text-end">Actions</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse($members as $member)
                        <tr>
                            <td>{{ $member->name ?: '—' }}</td>
                            <td><a href="mailto:{{ $member->email }}" class="text-decoration-none">{{ $member->email }}</a></td>
                            <td>{{ $member->client?->name ?? '—' }}</td>
                            <td><span class="badge text-bg-light border">{{ str($member->source_type)->replace('_', ' ')->title() }}</span></td>
                            <td><span class="badge text-bg-success">{{ ucfirst($member->status) }}</span></td>
                            @can('marketing.list.manage')
                                <td class="text-end">
                                    @if($member->contact_id)
                                        <form method="POST" action="{{ route('tech.marketing.lists.contacts.remove', [$list, $member->contact_id]) }}" class="mb-0" onsubmit="return confirm('Remove this contact from the marketing list?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-person-dash" aria-hidden="true"></i>
                                                Remove
                                            </button>
                                        </form>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ auth()->user()?->can('marketing.list.manage') ? 6 : 5 }}" class="text-center text-muted py-4">No recipients are currently eligible for this list.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($members->hasPages())
            <div class="card-footer">{{ $members->links() }}</div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
    <x-card.default title="Consent Scope">
        <dl class="row small mb-0">
            <dt class="col-6">Category</dt>
            <dd class="col-6 text-end">{{ $list->consentCategory?->name ?? 'General' }}</dd>
            <dt class="col-6">Consent</dt>
            <dd class="col-6 text-end">{{ $settings['consent_mode'] === 'opt_out' ? 'Opt-out' : 'Explicit opt-in' }}</dd>
            <dt class="col-6">Unsubscribe</dt>
            <dd class="col-6 text-end">{{ $settings['unsubscribe_mode'] === 'all_marketing' ? 'All marketing' : 'Category' }}</dd>
        </dl>
    </x-card.default>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const filter = document.getElementById('add_contact_filter');
            const rows = Array.from(document.querySelectorAll('.add-contact-row'));

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
