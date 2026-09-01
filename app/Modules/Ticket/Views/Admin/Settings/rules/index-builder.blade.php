@extends('layouts.default_tech')

@section('title', 'Ticket Rules')

@section('pageHeader')
    {{-- Page header --}}
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <h1 class="mb-1">Ticket Rules</h1>
            <p class="text-muted mb-0">Draft, publish, explicitly enable, and inspect immutable automation versions.</p>
        </div>
        <a href="{{ route('tech.admin.settings.tickets.rules.create') }}" class="btn btn-primary">Create Ticket Rule</a>
    </div>
@endsection

@section('sidebar')
    {{-- Ticket settings navigation --}}
    <x-nav.admin-menu group="tickets" />
@endsection

@section('content')
    @php
        $currentSort = (string) request('sort', 'weight');
        $currentDirection = request('direction') === 'desc' ? 'desc' : 'asc';
        $sortUrl = static function (string $column) use ($currentSort, $currentDirection): string {
            $parameters = request()->except('page');
            $parameters['sort'] = $column;
            $parameters['direction'] = $currentSort === $column && $currentDirection === 'asc'
                ? 'desc'
                : 'asc';

            return route('tech.admin.settings.tickets.rules', $parameters);
        };
        $ariaSort = static fn (string $column): string => $currentSort === $column
            ? ($currentDirection === 'desc' ? 'descending' : 'ascending')
            : 'none';
    @endphp

    <div class="col-12">
        {{-- Index filters --}}
        <x-card.default title="Filters">
            <form method="GET" action="{{ route('tech.admin.settings.tickets.rules') }}" class="row g-2 align-items-end">
                <div class="col-lg-4">
                    <label class="form-label" for="rule-search">Search</label>
                    <input id="rule-search" name="search" class="form-control" value="{{ request('search') }}" placeholder="Name or description">
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label" for="rule-state">State</label>
                    <select id="rule-state" name="state" class="form-select">
                        <option value="">All</option>
                        <option value="active" @selected(request('state') === 'active')>Enabled</option>
                        <option value="inactive" @selected(request('state') === 'inactive')>Disabled</option>
                        <option value="draft" @selected(request('state') === 'draft')>Has draft</option>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label" for="rule-lifecycle">Lifecycle</label>
                    <select id="rule-lifecycle" name="lifecycle" class="form-select">
                        <option value="">All</option>
                        @foreach(['legacy', 'published', 'disabled'] as $value)
                            <option value="{{ $value }}" @selected(request('lifecycle') === $value)>{{ Str::headline($value) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label" for="rule-sort">Sort</label>
                    <select id="rule-sort" name="sort" class="form-select">
                        @foreach(['weight' => 'Weight', 'name' => 'Name', 'published_at' => 'Published', 'draft_updated_at' => 'Draft updated', 'updated_at' => 'Updated'] as $value => $label)
                            <option value="{{ $value }}" @selected(request('sort', 'weight') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label" for="rule-direction">Direction</label>
                    <select id="rule-direction" name="direction" class="form-select">
                        <option value="asc" @selected(request('direction') !== 'desc')>Ascending</option>
                        <option value="desc" @selected(request('direction') === 'desc')>Descending</option>
                    </select>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-outline-primary">Apply filters</button>
                    <a href="{{ route('tech.admin.settings.tickets.rules') }}" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </x-card.default>

        {{-- Rule index --}}
        <x-card.default title="Rules">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th scope="col" aria-sort="{{ $ariaSort('name') }}">
                                <a href="{{ $sortUrl('name') }}" @if($currentSort === 'name') aria-current="true" @endif>
                                    Rule
                                    <span class="visually-hidden">Sort by name; next direction {{ $currentSort === 'name' && $currentDirection === 'asc' ? 'descending' : 'ascending' }}.</span>
                                </a>
                            </th>
                            <th scope="col" aria-sort="{{ $ariaSort('draft_updated_at') }}">
                                <a href="{{ $sortUrl('draft_updated_at') }}" @if($currentSort === 'draft_updated_at') aria-current="true" @endif>
                                    State
                                    <span class="visually-hidden">Sort by draft update; next direction {{ $currentSort === 'draft_updated_at' && $currentDirection === 'asc' ? 'descending' : 'ascending' }}.</span>
                                </a>
                            </th>
                            <th scope="col" aria-sort="{{ $ariaSort('published_at') }}">
                                <a href="{{ $sortUrl('published_at') }}" @if($currentSort === 'published_at') aria-current="true" @endif>
                                    Version
                                    <span class="visually-hidden">Sort by publication time; next direction {{ $currentSort === 'published_at' && $currentDirection === 'asc' ? 'descending' : 'ascending' }}.</span>
                                </a>
                            </th>
                            <th scope="col" aria-sort="{{ $ariaSort('weight') }}">
                                <a href="{{ $sortUrl('weight') }}" @if($currentSort === 'weight') aria-current="true" @endif>
                                    Definition
                                    <span class="visually-hidden">Sort by weight; next direction {{ $currentSort === 'weight' && $currentDirection === 'asc' ? 'descending' : 'ascending' }}.</span>
                                </a>
                            </th>
                            <th scope="col">Execution</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rules as $rule)
                            @php
                                $definition = (array) $rule->publishedVersion?->definition_json;
                                $thenActions = $definition !== []
                                    ? (array) ($definition['then_actions'] ?? [])
                                    : (array) $rule->actions_json;
                                $elseActions = (array) ($definition['else_actions'] ?? []);
                                $thenCount = count($thenActions);
                                $elseCount = count($elseActions);
                                $trigger = $definition['trigger'] ?? $rule->trigger;
                                $stopProcessing = $definition !== []
                                    ? (bool) data_get($definition, 'flow.stop_processing', false)
                                    : (bool) $rule->stop_processing;
                                $publishedSchema = $rule->publishedVersion?->definition_schema_version;
                                $isSchema2Publication = (int) $publishedSchema
                                    === \App\Modules\Ticket\Support\TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION;
                                $isDraftOnly = ! $rule->publishedVersion && $rule->draft_payload_json !== null;
                                $isSchema1Compatibility = ! $isDraftOnly
                                    && (! $rule->publishedVersion
                                        || (int) $publishedSchema === \App\Modules\Ticket\Support\TicketRuleDefinitionRegistry::SCHEMA_VERSION);
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $rule->name }}</div>
                                    <div class="small text-muted">
                                        Weight {{ $rule->publishedVersion?->weight ?? $rule->weight }}
                                        · ID {{ $rule->id }}
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        <span class="badge {{ $rule->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                                            {{ $rule->is_active ? 'Enabled' : 'Disabled' }}
                                        </span>
                                        <span class="badge text-bg-light">{{ Str::headline($rule->lifecycle_status) }}</span>
                                        @if($rule->draft_payload_json)
                                            <span class="badge text-bg-warning">Draft</span>
                                        @endif
                                    </div>
                                    @if($rule->published_at)
                                        <div class="small text-muted mt-1">Published {{ $rule->published_at->diffForHumans() }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if($rule->publishedVersion)
                                        <strong>v{{ $rule->publishedVersion->version_number }}</strong>
                                        <div class="small text-muted">Schema {{ $rule->publishedVersion->definition_schema_version }}</div>
                                    @elseif($isDraftOnly)
                                        <span class="text-muted">Draft only</span>
                                    @else
                                        <span class="text-muted">Legacy only</span>
                                    @endif
                                </td>
                                <td>
                                    <div>{{ Str::headline(str_replace('ticket.', '', (string) $trigger)) }}</div>
                                    <div class="small text-muted">Then {{ $thenCount }} · Else {{ $elseCount }}</div>
                                    <div class="small text-muted">Flow: {{ $stopProcessing ? 'Stop' : 'Continue' }}</div>
                                </td>
                                <td>
                                    @if($rule->latestExecution)
                                        <span class="badge text-bg-light">{{ Str::headline($rule->latestExecution->status) }}</span>
                                        <div class="small text-muted">{{ $rule->latestExecution->created_at?->diffForHumans() }}</div>
                                    @else
                                        <span class="text-muted">Never</span>
                                    @endif
                                    <div class="small {{ $rule->failed_executions_count ? 'text-danger' : 'text-muted' }}">
                                        {{ $rule->executions_count }} run(s), {{ $rule->failed_executions_count }} failed
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex flex-wrap justify-content-end gap-1">
                                        <a href="{{ route('tech.admin.settings.tickets.rules.edit', $rule) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                        @if(request()->user()?->can('ticket.view') && request()->user()?->can('ticket.rule_execution_view'))
                                            <a href="{{ route('tech.admin.settings.tickets.rules.executions.index', ['rule_id' => $rule->id]) }}" class="btn btn-sm btn-outline-secondary">History</a>
                                        @endif
                                        @if($isDraftOnly)
                                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Publish an immutable version before changing runtime status">Draft only</button>
                                        @elseif($isSchema2Publication)
                                            @if(($publishedV2ToggleAvailable ?? false)
                                                && request()->user()?->can('ticket.manage_rules')
                                                && request()->user()?->can('ticket.rule_publish'))
                                                <form method="POST" action="{{ route('tech.admin.settings.tickets.rules.toggle', $rule) }}">
                                                    @csrf
                                                    <input type="hidden" name="published_version_id" value="{{ $rule->publishedVersion->id }}">
                                                    <input type="hidden" name="definition_checksum" value="{{ $rule->publishedVersion->definition_checksum }}">
                                                    <input type="hidden" name="expected_enabled" value="{{ $rule->is_active ? 1 : 0 }}">
                                                    <button type="submit" class="btn btn-sm {{ $rule->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                                        {{ $rule->is_active ? 'Disable' : 'Enable' }}
                                                    </button>
                                                </form>
                                            @else
                                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Schema 2 status changes require active v2 runtime authority and publication permission">
                                                    {{ $rule->is_active ? 'Disable unavailable' : 'Enable unavailable' }}
                                                </button>
                                            @endif
                                        @elseif($isSchema1Compatibility)
                                            <form method="POST" action="{{ route('tech.admin.settings.tickets.rules.toggle', $rule) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm {{ $rule->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                                    {{ $rule->is_active ? 'Disable' : 'Enable' }}
                                                </button>
                                            </form>
                                        @else
                                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Unsupported schema</button>
                                        @endif
                                    </div>
                                    @if($isSchema2Publication && ! ($publishedV2ToggleAvailable ?? false))
                                        <div class="small text-muted mt-1">Schema 2 status changes remain unavailable until v2 runtime authority and its protected actor are ready.</div>
                                    @endif
                                    @if(! $rule->is_active && $rule->publishedVersion)
                                        <div class="small text-muted mt-1">Publish does not enable automatically.</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No Ticket Rules match these filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3">
                {{ $rules->links() }}
            </div>
        </x-card.default>
    </div>
@endsection
