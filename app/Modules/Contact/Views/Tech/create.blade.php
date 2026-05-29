@extends('layouts.default_tech')

@section('title', 'Add Contact')

@section('pageName')
    <h3>Add Contact</h3>
@endsection

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">Add Contact</h1>
        <x-buttons.back url="{{ route('tech.contacts.index') }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
<div class="container-fluid px-0">
    <!-- Section: Contact creation form. -->
    <form method="POST" action="{{ route('tech.contacts.store') }}">
        @csrf

        <div class="card mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0">Contact</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="display_name" class="form-label">Name</label>
                        <input id="display_name" name="display_name" type="text" class="form-control @error('display_name') is-invalid @enderror" value="{{ old('display_name') }}" required>
                        @error('display_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="organization_name" class="form-label">Organization</label>
                        <input id="organization_name" name="organization_name" type="text" class="form-control @error('organization_name') is-invalid @enderror" value="{{ old('organization_name') }}">
                        @error('organization_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="email" class="form-label">Email</label>
                        <input id="email" name="email" type="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email') }}">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="phone" class="form-label">Phone</label>
                        <input id="phone" name="phone" type="text" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone') }}">
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="job_title" class="form-label">Role or title</label>
                        <input id="job_title" name="job_title" type="text" class="form-control @error('job_title') is-invalid @enderror" value="{{ old('job_title') }}">
                        @error('job_title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label for="relation_type" class="form-label">Relation</label>
                        <input id="relation_type" name="relation_type" type="text" class="form-control @error('relation_type') is-invalid @enderror" value="{{ old('relation_type', 'contact') }}">
                        @error('relation_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label for="preferred_language" class="form-label">Language</label>
                        <input id="preferred_language" name="preferred_language" type="text" class="form-control @error('preferred_language') is-invalid @enderror" value="{{ old('preferred_language') }}" placeholder="nb">
                        @error('preferred_language')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <!-- Section: Optional client and site binding for client-context workflows. -->
        <div class="card mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0">Client context</h2>
            </div>
            <div class="card-body">
                @if($activeSite)
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Client</label>
                            <div class="form-control bg-light">{{ $activeClient?->name ?: $activeSite?->client?->name ?: '—' }}</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Site</label>
                            <div class="form-control bg-light">{{ $activeSite?->name ?: '—' }}</div>
                        </div>
                    </div>
                @elseif($activeClient)
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Client</label>
                            <div class="form-control bg-light">{{ $activeClient->name }}</div>
                        </div>
                        <div class="col-md-6">
                            <label for="site_id" class="form-label">Site</label>
                            <select id="site_id" name="site_id" class="form-select @error('site_id') is-invalid @enderror">
                                <option value="">No site relation</option>
                                @foreach($sites->where('client_id', $activeClient->id) as $site)
                                    <option value="{{ $site->id }}" @selected((string) old('site_id') === (string) $site->id)>{{ $site->name }}</option>
                                @endforeach
                            </select>
                            @error('site_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                @else
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="client_id" class="form-label">Client</label>
                            <select id="client_id" name="client_id" class="form-select @error('client_id') is-invalid @enderror">
                                <option value="">No client relation</option>
                                @foreach($clients as $client)
                                    <option value="{{ $client->id }}" @selected((string) old('client_id') === (string) $client->id)>{{ $client->name }}</option>
                                @endforeach
                            </select>
                            @error('client_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="site_id" class="form-label">Site</label>
                            <select id="site_id" name="site_id" class="form-select @error('site_id') is-invalid @enderror">
                                <option value="">No site relation</option>
                                @foreach($sites as $site)
                                    <option value="{{ $site->id }}" @selected((string) old('site_id') === (string) $site->id)>{{ $site->name }} @if($site->client) ({{ $site->client->name }}) @endif</option>
                                @endforeach
                            </select>
                            @error('site_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('tech.contacts.index') }}" class="btn btn-outline-secondary btn-sm">Cancel</a>
            <button type="submit" class="btn btn-primary btn-sm">Save contact</button>
        </div>
    </form>
</div>
@endsection

@section('sidebar')
    <x-nav.side-bar :items="[
        ['name' => 'Clients', 'route' => 'tech.clients.index', 'icon' => 'bi-building'],
        ['name' => 'Sites', 'route' => 'tech.clients.sites.index', 'icon' => 'bi-diagram-3'],
        ['name' => 'Contacts', 'route' => 'tech.contacts.index', 'icon' => 'bi-person-lines-fill'],
    ]" title="Client workspace" />
@endsection
