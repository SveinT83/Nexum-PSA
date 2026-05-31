@extends('layouts.default_tech')

@section('title', 'Edit '.$contact->display_name)

@section('pageName')
    <h3>Edit Contact</h3>
@endsection

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">Edit Contact</h1>
        <x-buttons.back url="{{ route('tech.contacts.show', $contact) }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
<div class="container-fluid px-0">
    <livewire:tech.contacts.contact-form
        :contact-id="$contact->id"
        :active-client-id="$activeClient?->id"
        :active-site-id="$activeSite?->id"
    />
</div>
@endsection

@section('sidebar')
    <x-nav.side-bar :items="[
        ['name' => 'Clients', 'route' => 'tech.clients.index', 'icon' => 'bi-building'],
        ['name' => 'Sites', 'route' => 'tech.clients.sites.index', 'icon' => 'bi-diagram-3'],
        ['name' => 'Contacts', 'route' => 'tech.contacts.index', 'icon' => 'bi-person-lines-fill'],
    ]" title="Client workspace" />
@endsection
