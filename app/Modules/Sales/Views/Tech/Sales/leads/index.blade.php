@extends('layouts.default_tech')

@section('title', 'Sales Leads')

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="mb-0">Leads</h1>
        <x-buttons.back url="{{ route('tech.sales.index') }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        @php
            $sort = $filters['sort'] ?? 'name';
            $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
            $leadActiveFilterCount = collect([
                filled($filters['category'] ?? null),
                filled($filters['tag'] ?? null),
                filled($filters['temperature'] ?? null),
                ($filters['group_by'] ?? '') !== '',
            ])->filter()->count();
            $leadFiltersOpen = $leadActiveFilterCount > 0;
            $sortLink = function (string $column, string $defaultDirection = 'asc') use ($sort, $direction) {
                $nextDirection = $sort === $column && $direction === 'asc' ? 'desc' : ($sort === $column ? 'asc' : $defaultDirection);

                return request()->fullUrlWithQuery([
                    'sort' => $column,
                    'direction' => $nextDirection,
                ]);
            };
            $sortIcon = function (string $column) use ($sort, $direction) {
                if ($sort !== $column) {
                    return 'bi-arrow-down-up';
                }

                return $direction === 'asc' ? 'bi-sort-alpha-down' : 'bi-sort-alpha-up';
            };
        @endphp

        <form method="GET" action="{{ route('tech.sales.leads.index') }}" class="card mb-3">
            <div class="card-body">
                <input type="hidden" name="sort" value="{{ $sort }}">
                <input type="hidden" name="direction" value="{{ $direction }}">

                <label for="lead_search" class="form-label text-muted small fw-bold text-uppercase">Search</label>
                <div class="input-group input-group-sm">
                    <input type="search" id="lead_search" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="Client name, org no, billing email, or website">
                    <button type="submit" class="btn btn-outline-secondary">Search</button>
                    <button
                        class="btn btn-outline-secondary"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#leadFiltersCollapse"
                        aria-expanded="{{ $leadFiltersOpen ? 'true' : 'false' }}"
                        aria-controls="leadFiltersCollapse"
                        title="Filters">
                        <i class="bi bi-funnel" aria-hidden="true"></i>
                        @if($leadActiveFilterCount > 0)
                            <span class="badge text-bg-secondary ms-1">{{ $leadActiveFilterCount }}</span>
                        @endif
                    </button>
                </div>

                <div id="leadFiltersCollapse" class="collapse {{ $leadFiltersOpen ? 'show' : '' }} mt-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label for="category" class="form-label small text-muted mb-1">Category</label>
                            <select id="category" name="category" class="form-select form-select-sm">
                                <option value="">All categories</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}" @selected(($filters['category'] ?? '') == $category->id)>{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="tag" class="form-label small text-muted mb-1">Tag</label>
                            <select id="tag" name="tag" class="form-select form-select-sm">
                                <option value="">All tags</option>
                                @foreach($tags as $tag)
                                    <option value="{{ $tag->id }}" @selected(($filters['tag'] ?? '') == $tag->id)>{{ $tag->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="temperature" class="form-label small text-muted mb-1">Heat</label>
                            <select id="temperature" name="temperature" class="form-select form-select-sm">
                                <option value="">All</option>
                                @for($i = 5; $i >= 1; $i--)
                                    <option value="{{ $i }}" @selected(($filters['temperature'] ?? '') == $i)>{{ $i }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="group_by" class="form-label small text-muted mb-1">Group</label>
                            <select id="group_by" name="group_by" class="form-select form-select-sm">
                                <option value="" @selected(($filters['group_by'] ?? '') === '')>No grouping</option>
                                <option value="category" @selected(($filters['group_by'] ?? '') === 'category')>Category</option>
                                <option value="temperature" @selected(($filters['group_by'] ?? '') === 'temperature')>Heat</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-grid">
                            <button type="submit" class="btn btn-sm btn-secondary">Apply filters</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>
                            <a href="{{ $sortLink('name') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Client <i class="bi {{ $sortIcon('name') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('contacts', 'desc') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Contacts <i class="bi {{ $sortIcon('contacts') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('assets', 'desc') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Assets <i class="bi {{ $sortIcon('assets') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('status') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Status <i class="bi {{ $sortIcon('status') }}"></i>
                            </a>
                        </th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @if($clients->isEmpty())
                        <tr>
                            <td colspan="5" class="text-center text-muted py-5">No lead candidates match this view.</td>
                        </tr>
                    @endif
                    @foreach($groupedClients as $groupLabel => $groupClients)
                        @if($groupBy)
                            <tr class="table-light">
                                <td colspan="5" class="fw-semibold">{{ $groupLabel }} <span class="badge text-bg-light border">{{ $groupClients->count() }}</span></td>
                            </tr>
                        @endif
                    @foreach($groupClients as $client)
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $client->name }}</span>
                                <div class="text-muted small">{{ $client->org_no ?: $client->billing_email }}</div>
                                <div class="d-flex flex-wrap gap-1 mt-1">
                                    @if($client->salesCategory)
                                        <span class="badge text-bg-light border">{{ $client->salesCategory->name }}</span>
                                    @endif
                                    @foreach($client->tags as $tag)
                                        <span class="badge text-bg-secondary">{{ $tag->name }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td>
                                <div>{{ $client->contacts_count }}</div>
                                <div class="text-muted small">{{ $client->billing_email ?: '-' }}</div>
                            </td>
                            <td>
                                <div>{{ $client->assets_count }}</div>
                                <div class="text-muted small">
                                    @if($client->website)
                                        <a href="{{ $client->website }}" target="_blank" rel="noopener">{{ parse_url($client->website, PHP_URL_HOST) ?: $client->website }}</a>
                                    @else
                                        -
                                    @endif
                                </div>
                            </td>
                            <td>
                                @if(in_array($client->id, $activeOpportunityClientIds, true))
                                    <span class="badge text-bg-info">Active opportunity</span>
                                @else
                                    <span class="badge text-bg-light border">Lead candidate</span>
                                @endif
                                <div class="d-flex gap-1 mt-2" title="Lead heat">
                                    @for($i = 1; $i <= 5; $i++)
                                        <span class="d-inline-block rounded {{ $i <= $client->lead_temperature ? 'bg-danger' : 'bg-secondary-subtle' }}" style="width: 1.1rem; height: .35rem;"></span>
                                    @endfor
                                </div>
                                @php($marketingEngagement = $client->marketing_engagement_summary ?? [])
                                @if(($marketingEngagement['score'] ?? 0) > 0 || ($marketingEngagement['opens'] ?? 0) > 0 || ($marketingEngagement['clicks'] ?? 0) > 0)
                                    <div class="small text-muted mt-2">
                                        Marketing:
                                        <span class="fw-semibold">{{ $marketingEngagement['score'] ?? 0 }}</span>
                                        score,
                                        {{ $marketingEngagement['opens'] ?? 0 }} opens,
                                        {{ $marketingEngagement['clicks'] ?? 0 }} clicks
                                    </div>
                                @endif
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#classifyLead{{ $client->id }}" aria-expanded="false">
                                        Classify
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#startLead{{ $client->id }}">
                                        Start sales process
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr class="collapse" id="classifyLead{{ $client->id }}">
                            <td colspan="5" class="bg-light">
                                <form method="POST" action="{{ route('tech.sales.leads.classification.update', $client) }}" class="row g-2 align-items-end">
                                    @csrf
                                    @method('PATCH')
                                    <div class="col-md-3">
                                        <label class="form-label">Category</label>
                                        <select name="sales_category_id" class="form-select form-select-sm">
                                            <option value="">Uncategorized</option>
                                            @foreach($classifyCategories as $category)
                                                <option value="{{ $category->id }}" @selected($client->sales_category_id === $category->id)>{{ $category->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Heat</label>
                                        <select name="lead_temperature" class="form-select form-select-sm">
                                            @for($i = 1; $i <= 5; $i++)
                                                <option value="{{ $i }}" @selected($client->lead_temperature === $i)>{{ $i }}</option>
                                            @endfor
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Website</label>
                                        <input type="url" name="website" class="form-control form-control-sm" value="{{ $client->website }}" placeholder="https://example.com">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Tags</label>
                                        <div class="sales-tag-input form-control form-control-sm d-flex flex-wrap align-items-center gap-1 p-1" data-sales-tag-input>
                                            @foreach($client->tags as $tag)
                                                <span class="badge text-bg-secondary d-inline-flex align-items-center gap-1" data-tag-chip="{{ $tag->name }}">
                                                    {{ $tag->name }}
                                                    <button type="button" class="btn-close btn-close-white" data-remove-tag aria-label="Remove {{ $tag->name }}"></button>
                                                    <input type="hidden" name="tag_names[]" value="{{ $tag->name }}">
                                                </span>
                                            @endforeach
                                            <input type="text" class="sales-tag-input__field border-0 flex-grow-1 px-1" list="salesTagSuggestions" placeholder="Add tag">
                                        </div>
                                    </div>
                                    <div class="col-md-1 d-grid">
                                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($clients->hasPages())
                <div class="card-footer">{{ $clients->links() }}</div>
            @endif
        </div>

        @foreach($clients as $client)
            <div class="modal fade" id="startLead{{ $client->id }}" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <form method="POST" action="{{ route('tech.sales.leads.start', $client) }}">
                            @csrf
                            <div class="modal-header">
                                <h5 class="modal-title">Start sales process for {{ $client->name }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label class="form-label">Title</label>
                                        <input type="text" name="title" class="form-control" value="{{ $client->name }} service opportunity" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Type</label>
                                        <select name="type" class="form-select">
                                            @foreach($types as $key => $label)
                                                <option value="{{ $key }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Owner</label>
                                        <select name="owner_id" class="form-select">
                                            @foreach($owners as $owner)
                                                <option value="{{ $owner->id }}" @selected(auth()->id() === $owner->id)>{{ $owner->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Next follow-up</label>
                                        <input type="datetime-local" name="next_follow_up_at" class="form-control">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Next action</label>
                                        <select name="next_follow_up_type" class="form-select">
                                            <option value="">No action selected</option>
                                            @foreach($nextActions as $key => $label)
                                                <option value="{{ $key }}" @selected($key === 'call')>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Users estimate</label>
                                        <input type="number" name="user_count_estimate" min="0" class="form-control">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Workstations estimate</label>
                                        <input type="number" name="workstation_count_estimate" min="0" class="form-control">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Expected close date</label>
                                        <input type="date" name="expected_close_date" class="form-control">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Need / summary</label>
                                        <textarea name="needs" class="form-control" rows="3"></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">Start sales process</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach

        <datalist id="salesTagSuggestions">
            @foreach($classifyTags as $tag)
                <option value="{{ $tag->name }}"></option>
            @endforeach
        </datalist>
    </div>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const normalizeTag = (value) => value.trim().replace(/\s+/g, ' ');

            document.querySelectorAll('[data-sales-tag-input]').forEach((container) => {
                const input = container.querySelector('.sales-tag-input__field');

                const existingNames = () => Array.from(container.querySelectorAll('input[name="tag_names[]"]'))
                    .map((hidden) => hidden.value.toLowerCase());

                const addTag = (rawName) => {
                    const name = normalizeTag(rawName);

                    if (!name || existingNames().includes(name.toLowerCase())) {
                        input.value = '';
                        return;
                    }

                    const chip = document.createElement('span');
                    chip.className = 'badge text-bg-secondary d-inline-flex align-items-center gap-1';
                    chip.dataset.tagChip = name;
                    chip.append(document.createTextNode(name));

                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.className = 'btn-close btn-close-white';
                    removeButton.dataset.removeTag = '';
                    removeButton.setAttribute('aria-label', `Remove ${name}`);

                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'tag_names[]';
                    hidden.value = name;

                    chip.append(removeButton, hidden);
                    container.insertBefore(chip, input);
                    input.value = '';
                };

                input.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ',') {
                        event.preventDefault();
                        addTag(input.value);
                    }

                    if (event.key === 'Backspace' && input.value === '') {
                        const chips = container.querySelectorAll('[data-tag-chip]');
                        chips[chips.length - 1]?.remove();
                    }
                });

                input.addEventListener('change', () => addTag(input.value));
                input.closest('form')?.addEventListener('submit', () => addTag(input.value));

                container.addEventListener('click', (event) => {
                    if (event.target.matches('[data-remove-tag]')) {
                        event.preventDefault();
                        event.target.closest('[data-tag-chip]')?.remove();
                        return;
                    }

                    input.focus();
                });
            });
        });
    </script>
@endsection
