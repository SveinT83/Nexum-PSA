@extends('layouts.default_tech')

@section('title', 'Admin page')

@section('pageHeader')
    {{-- Page Header with title and breadcrumbs --}}
    <h1>Admin page</h1>
@endsection

@section('content')
    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Admin card menu -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <div class="row">

        <!-- ------------------------------------------------- -->
        <!-- Contracts & Services Settings -->
        <!-- ------------------------------------------------- -->
        <div class="col-md-4">
            <x-card.default title="Contracts & Services Settings">
                <li><a class="dropdown-item" href="{{ route('tech.admin.settings.cs.contracts') }}">Contracts</a></li>
                <li><a class="dropdown-item" href="{{ route('tech.admin.settings.cs.services') }}">Services</a></li>
            </x-card.default>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Economy Settings -->
        <!-- ------------------------------------------------- -->
        <div class="col-md-4">
            <x-card.default title="Economy Settings">
                <li><a class="dropdown-item" href="{{ route('tech.admin.settings.economy') }}">Economy</a></li>
            </x-card.default>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Email Settings -->
        <!-- ------------------------------------------------- -->
        <div class="col-md-4">
            <x-card.default title="Email Settings">
                <li><a class="dropdown-item" href="{{ route('tech.admin.settings.email.accounts') }}">Accounts</a></li>
                <li><a class="dropdown-item" href="{{ route('tech.admin.settings.email.config') }}">Config</a></li>
                <li><a class="dropdown-item" href="{{ route('tech.admin.settings.email.rules') }}">Rules</a></li>
            </x-card.default>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Sales Settings -->
        <!-- ------------------------------------------------- -->
        <div class="col-md-4">
            <x-card.default title="Sales Settings">
                <li><a class="dropdown-item" href="{{ route('tech.admin.settings.sales.rules') }}">Rules</a></li>
                <li><a class="dropdown-item" href="{{ route('tech.admin.settings.sales.workflows') }}">Workflows</a></li>
            </x-card.default>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Ticket Settings -->
        <!-- ------------------------------------------------- -->
        <div class="col-md-4">
            <x-card.default title="Ticket Settings">
                <li><a class="dropdown-item" href="{{ route('tech.admin.settings.tickets') }}">Tickets</a></li>
                <li><a class="dropdown-item" href="{{ route('tech.admin.settings.tickets.rules') }}">Rules</a></li>
                <li><a class="dropdown-item" href="{{ route('tech.admin.settings.tickets.workflows') }}">Workflows</a></li>
            </x-card.default>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- TemplatesManagement -->
        <!-- ------------------------------------------------- -->
        <div class="col-md-4">
            <x-card.default title="Templates">
                <li><a class="dropdown-item" href="{{ route('tech.admin.system.templatesManagement.index') }}">Templates</a></li>
            </x-card.default>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Users -->
        <!-- ------------------------------------------------- -->
        <div class="col-md-4">
            <x-card.default title="Users">
                <li><a class="dropdown-item" href="{{ route('tech.admin.users.index') }}">Users</a></li>
            </x-card.default>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- System -->
        <!-- ------------------------------------------------- -->
        <div class="col-md-4">
            <x-card.default title="System">
                <a class="dropdown-item" href="{{ route('tech.admin.system.category.index') }}">Categories</a>
                <a class="dropdown-item" href="{{ route('tech.admin.system.tag.index') }}">Tags</a>
            </x-card.default>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Integrations -->
        <!-- ------------------------------------------------- -->
        <div class="col-md-4">
            <x-card.default title="Integrations">
                <li><a class="dropdown-item" href="{{ route('tech.admin.system.integrations.index') }}">All Integrations</a></li>
                <li><a class="dropdown-item" href="{{ route('tech.admin.system.integrations.api.index') }}">API Management</a></li>
            </x-card.default>
        </div>

    </div>

@endsection

@section('sidebar')
    <h3>Tech Sidebar</h3>
    <ul>
        <li><a href="#">System Status</a></li>
        <li><a href="#">Task Management</a></li>
        <li><a href="#">Reports</a></li>
    </ul>
@endsection

@section('rightbar')
    <h3>Notifications</h3>
    <ul>
        <li>No new notifications.</li>
    </ul>
@endsection
