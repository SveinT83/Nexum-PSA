@extends('layouts.default_tech')

@section('title', 'Contacts')

@section('pageName')
    <h3>Contacts</h3>
@endsection

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">Contacts</h1>
        <x-buttons.back url="{{ route('tech.clients.index') }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
@php
    $hasContext = $activeClient || $activeSite;
    $hasFilterOverride = $clientFilterWasOverridden || $siteFilterWasOverridden;
    $activeFilterCount = (int) (bool) $selectedClientId + (int) (bool) $selectedSiteId;
@endphp
<div class="container-fluid px-0">
    <!-- Section: Search controls for the Contact workspace. -->
    <div class="card mb-3">
        <div class="card-body">
            @if($hasContext)
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    @if($activeClient)
                        <span class="badge text-bg-light border">{{ $activeClient->name }}</span>
                    @endif
                    @if($activeSite)
                        <span class="badge text-bg-light border">{{ $activeSite->name }}</span>
                    @endif
                    <form method="POST" action="{{ route('tech.contacts.context.clear') }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-link text-muted p-0 lh-1" title="Clear client context" aria-label="Clear client context">
                            <i class="bi bi-x-circle"></i>
                        </button>
                    </form>
                </div>
            @endif
            <form method="GET" action="{{ route('tech.contacts.index') }}" class="mb-0">
                <div class="input-group input-group-sm">
                    <input id="contact_index_search" name="q" type="search" class="form-control" value="{{ $search }}" placeholder="Name, email, phone, title, or organization">
                    <button class="btn btn-outline-secondary" type="submit">Search</button>
                    <button class="btn btn-outline-secondary position-relative" type="button" data-bs-toggle="collapse" data-bs-target="#contactIndexFilters" aria-expanded="false" aria-controls="contactIndexFilters" title="Filters">
                        <i class="bi bi-funnel"></i>
                        @if($activeFilterCount > 0)
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-primary">{{ $activeFilterCount }}</span>
                        @endif
                    </button>
                    @if($search !== '' || $hasFilterOverride)
                        <a class="btn btn-outline-secondary" href="{{ route('tech.contacts.index') }}">Clear</a>
                    @endif
                </div>
                <div class="collapse mt-3" id="contactIndexFilters">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label for="contact_filter_client_id" class="form-label small text-muted text-uppercase fw-bold">Client</label>
                            <select id="contact_filter_client_id" name="client_id" class="form-select form-select-sm">
                                <option value="">All clients</option>
                                @foreach($clients as $client)
                                    <option value="{{ $client->id }}" @selected((string) $selectedClientId === (string) $client->id)>{{ $client->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="contact_filter_site_id" class="form-label small text-muted text-uppercase fw-bold">Site</label>
                            <select id="contact_filter_site_id" name="site_id" class="form-select form-select-sm">
                                <option value="">All sites</option>
                                @foreach($sites as $site)
                                    <option value="{{ $site->id }}" @selected((string) $selectedSiteId === (string) $site->id)>{{ $site->name }} @if($site->client) ({{ $site->client->name }}) @endif</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Section: Operational contact list. -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Contact list</h2>
            <x-buttons.addlink url="{{ route('tech.contacts.create') }}" class="mb-0">Add contact</x-buttons.addlink>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 28%">Contact</th>
                        <th style="width: 22%">Email</th>
                        <th style="width: 16%">Phone</th>
                        <th style="width: 18%">Client</th>
                        <th style="width: 16%">Site</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($contacts as $contact)
                        @php
                            $primaryEmail = $contact->emails->firstWhere('is_primary', true) ?? $contact->emails->first();
                            $primaryPhone = $contact->phones->firstWhere('is_primary', true) ?? $contact->phones->first();
                            $clientRelation = $contact->relations->first(fn ($relation) => $relation->related instanceof \App\Models\Clients\Client);
                            $siteRelation = $contact->relations->first(fn ($relation) => $relation->related instanceof \App\Models\Clients\ClientSite);
                            $site = $siteRelation?->related ?: $contact->clientUser?->site;
                            $client = $clientRelation?->related ?: $site?->client ?: $contact->clientUser?->site?->client;
                        @endphp
                        <tr
                            class="cursor-pointer contact-index-row"
                            role="link"
                            tabindex="0"
                            data-href="{{ route('tech.contacts.show', $contact) }}"
                            aria-label="Open contact {{ $contact->display_name }}">
                            <td>
                                <a href="{{ route('tech.contacts.show', $contact) }}" class="fw-semibold text-decoration-none">{{ $contact->display_name }}</a>
                                <div class="small text-muted">{{ $contact->job_title ?: $contact->organization_name ?: '—' }}</div>
                            </td>
                            <td>
                                @if($primaryEmail)
                                    <a href="mailto:{{ $primaryEmail->email }}">{{ $primaryEmail->email }}</a>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($primaryPhone)
                                    <a href="tel:{{ $primaryPhone->phone }}">{{ $primaryPhone->phone }}</a>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                {{ $client?->name ?: '—' }}
                            </td>
                            <td>{{ $site?->name ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No contacts found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($contacts->hasPages())
            <div class="card-footer">{{ $contacts->links() }}</div>
        @endif
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('tr[data-href]').forEach(function (row) {
            row.addEventListener('click', function (event) {
                if (event.target.closest('a, button, input, select, textarea')) {
                    return;
                }

                window.location.href = row.dataset.href;
            });

            row.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    window.location.href = row.dataset.href;
                }
            });
        });
    });
</script>
@endsection

@section('sidebar')
    <x-nav.side-bar :items="[
        ['name' => 'Clients', 'route' => 'tech.clients.index', 'icon' => 'bi-building'],
        ['name' => 'Sites', 'route' => 'tech.clients.sites.index', 'icon' => 'bi-diagram-3'],
        ['name' => 'Contacts', 'route' => 'tech.contacts.index', 'icon' => 'bi-person-lines-fill'],
    ]" title="Client workspace" />
@endsection

@section('rightbar')
    <!-- Section: Contact migration state counters. -->
    <x-card.default title="Contact stats">
        <div class="row row-cols-2 g-2 text-center">
            <div class="col">
                <div class="border rounded bg-light py-2 px-1">
                    <div class="small text-muted text-uppercase">Total</div>
                    <div class="fw-bold fs-5 lh-1">{{ $stats['total'] }}</div>
                </div>
            </div>
            <div class="col">
                <div class="border rounded bg-light py-2 px-1">
                    <div class="small text-muted text-uppercase">Active</div>
                    <div class="fw-bold fs-5 lh-1">{{ $stats['active'] }}</div>
                </div>
            </div>
            <div class="col">
                <div class="border rounded bg-light py-2 px-1">
                    <div class="small text-muted text-uppercase">People</div>
                    <div class="fw-bold fs-5 lh-1">{{ $stats['people'] }}</div>
                </div>
            </div>
            <div class="col">
                <div class="border rounded bg-light py-2 px-1">
                    <div class="small text-muted text-uppercase">Email</div>
                    <div class="fw-bold fs-5 lh-1">{{ $stats['with_email'] }}</div>
                </div>
            </div>
        </div>
    </x-card.default>
@endsection
