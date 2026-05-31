@extends('layouts.default_tech')

@section('title', $contact->display_name)

@section('pageName')
    <h3>{{ $contact->display_name }}</h3>
@endsection

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">{{ $contact->display_name }}</h1>
        <div class="d-flex gap-2">
            <x-buttons.editlink url="{{ route('tech.contacts.edit', $contact) }}" class="mb-0">Edit</x-buttons.editlink>
            <x-buttons.back url="{{ route('tech.contacts.index') }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
@php
    $clientRelation = $contact->relations->first(fn ($relation) => $relation->related instanceof \App\Models\Clients\Client);
    $siteRelation = $contact->relations->first(fn ($relation) => $relation->related instanceof \App\Models\Clients\ClientSite);
    $site = $siteRelation?->related ?: $contact->clientUser?->site;
    $client = $clientRelation?->related ?: $site?->client ?: $contact->clientUser?->site?->client;
    $organizationLabel = $client?->name ?: $contact->organization_name;
@endphp
<div class="container-fluid px-0">
    <!-- Section: Canonical contact identity. -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">Contact Details</h2>
            <span class="badge text-bg-light border">{{ ucfirst($contact->status) }}</span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="small text-muted text-uppercase">Type</div>
                    <div class="fw-semibold">{{ ucfirst(str_replace('_', ' ', $contact->type)) }}</div>
                </div>
                <div class="col-md-3">
                    <div class="small text-muted text-uppercase">Title</div>
                    <div class="fw-semibold">{{ $contact->job_title ?: '—' }}</div>
                </div>
                <div class="col-md-3">
                    <div class="small text-muted text-uppercase">Organization / Client</div>
                    <div class="fw-semibold">{{ $organizationLabel ?: '—' }}</div>
                </div>
                <div class="col-md-3">
                    <div class="small text-muted text-uppercase">Site</div>
                    <div class="fw-semibold">{{ $site?->name ?: '—' }}</div>
                </div>
                <div class="col-md-3">
                    <div class="small text-muted text-uppercase">Language</div>
                    <div class="fw-semibold">{{ $contact->communication_language ?: $contact->preferred_language ?: '—' }}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section: Communication methods. -->
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">Email Addresses</div>
                <div class="list-group list-group-flush">
                    @forelse($contact->emails as $email)
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <a href="mailto:{{ $email->email }}">{{ $email->email }}</a>
                            <span class="badge text-bg-light border">{{ $email->label }}</span>
                        </div>
                    @empty
                        <div class="list-group-item text-muted">No email addresses.</div>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">Phone Numbers</div>
                <div class="list-group list-group-flush">
                    @forelse($contact->phones as $phone)
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <a href="tel:{{ $phone->phone }}">{{ $phone->phone }}</a>
                            <span class="badge text-bg-light border">{{ $phone->label }}</span>
                        </div>
                    @empty
                        <div class="list-group-item text-muted">No phone numbers.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('sidebar')
    <x-nav.side-bar :items="[
        ['name' => 'Clients', 'route' => 'tech.clients.index', 'icon' => 'bi-building'],
        ['name' => 'Sites', 'route' => 'tech.clients.sites.index', 'icon' => 'bi-diagram-3'],
        ['name' => 'Contacts', 'route' => 'tech.contacts.index', 'icon' => 'bi-person-lines-fill'],
    ]" title="Client workspace" />
@endsection

@section('rightbar')
    <x-card.default title="Compatibility">
        <div class="small">
            <div class="d-flex justify-content-between gap-2 mb-2">
                <span class="text-muted">Client user</span>
                <span>{{ $contact->clientUser ? '#'.$contact->clientUser->id : '—' }}</span>
            </div>
            <div class="d-flex justify-content-between gap-2 mb-2">
                <span class="text-muted">User account</span>
                <span>{{ $contact->user ? '#'.$contact->user->id : '—' }}</span>
            </div>
            <div class="d-flex justify-content-between gap-2">
                <span class="text-muted">External refs</span>
                <span>{{ $contact->externalRefs->count() }}</span>
            </div>
        </div>
    </x-card.default>
@endsection
