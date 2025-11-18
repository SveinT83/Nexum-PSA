<ul class="nav nav-tabs">
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('tech.dashboard') ? 'active' : '' }}" aria-current="page" href="{{ route('tech.dashboard') }}">Dashboard</a>
    </li>

    @php
        $contractsAdminGroupActive = request()->routeIs('tech.admin*');
    @endphp

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Admin dropdown menu -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle {{ $contractsAdminGroupActive ? 'active' : '' }}" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">Admin</a>
        <ul class="dropdown-menu">

            <!-- ------------------------------------------------- -->
            <!-- Contracts & Services Settings -->
            <!-- ------------------------------------------------- -->
            <li><a class="nav-link disabled" aria-disabled="true">Contracts & Services</a></li>
            <li><a class="dropdown-item" href="{{ route('tech.admin.settings.cs.contracts') }}">Contracts</a></li>
            <li><a class="dropdown-item" href="{{ route('tech.admin.settings.cs.services') }}">Services</a></li>
            <li><hr class="dropdown-divider"></li>

            <!-- ------------------------------------------------- -->
            <!-- Email Settings -->
            <!-- ------------------------------------------------- -->
            <li><a class="nav-link disabled" aria-disabled="true">Email Settings</a></li>
            <li><a class="dropdown-item" href="{{ route('tech.admin.settings.email.accounts') }}">Accounts</a></li>
            <li><a class="dropdown-item" href="{{ route('tech.admin.settings.email.config') }}">Config</a></li>
            <li><a class="dropdown-item" href="{{ route('tech.admin.settings.email.rules') }}">Rules</a></li>
            <li><hr class="dropdown-divider"></li>

            <!-- ------------------------------------------------- -->
            <!-- Sales Settings -->
            <!-- ------------------------------------------------- -->
            <li><a class="nav-link disabled" aria-disabled="true">Sales Settings</a></li>
            <li><a class="dropdown-item" href="{{ route('tech.admin.settings.sales.rules') }}">Rules</a></li>
            <li><a class="dropdown-item" href="{{ route('tech.admin.settings.sales.workflows') }}">Workflows</a></li>

            <li><hr class="dropdown-divider"></li>

            <!-- ------------------------------------------------- -->
            <!-- Ticket Settings -->
            <!-- ------------------------------------------------- -->
            <li><a class="nav-link disabled" aria-disabled="true">Ticket Settings</a></li>
            <li><a class="dropdown-item" href="{{ route('tech.admin.settings.tickets') }}">Tickets</a></li>
            <li><a class="dropdown-item" href="{{ route('tech.admin.settings.tickets.rules') }}">Rules</a></li>
            <li><a class="dropdown-item" href="{{ route('tech.admin.settings.tickets.workflows') }}">Workflows</a></li>

            <li><hr class="dropdown-divider"></li>


            <!-- ------------------------------------------------- -->
            <!-- Templates -->
            <!-- ------------------------------------------------- -->
            <li><a class="nav-link disabled" aria-disabled="true">Templates</a></li>
            <li><a class="dropdown-item" href="{{ route('tech.admin.templates.index') }}">Templates</a></li>

            <li><hr class="dropdown-divider"></li>

            <!-- ------------------------------------------------- -->
            <!-- Users -->
            <!-- ------------------------------------------------- -->
            <li><a class="nav-link disabled" aria-disabled="true">Users</a></li>
            <li><a class="dropdown-item" href="{{ route('tech.admin.users.index') }}">Users</a></li>
        </ul>
    </li>


    <!-- ------------------------------------------------- -->
    <!-- Clients -->
    <!-- ------------------------------------------------- -->
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('tech.clients.index') ? 'active' : '' }}" href="{{ route('tech.clients.index') }}">Clients</a>
    </li>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Contracts & Services dropdown menu -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    @php
        $contractsGroupActive = request()->routeIs('tech.contracts.*') || request()->routeIs('tech.services.*');
    @endphp

    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle {{ $contractsGroupActive ? 'active' : '' }}" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">Contracts & Services</a>
        <ul class="dropdown-menu">

            <!-- ------------------------------------------------- -->
            <!-- Contracts -->
            <!-- ------------------------------------------------- -->
            <li><a class="dropdown-item {{ request()->routeIs('tech.contracts.index') ? 'active' : '' }}" href="{{ route('tech.contracts.index') }}">Contracts</a></li>
            
            <!-- ------------------------------------------------- -->
            <!-- Services -->
            <!-- ------------------------------------------------- -->
            <li><a class="dropdown-item {{ request()->routeIs('tech.services.index') ? 'active' : '' }}" href="{{ route('tech.services.index') }}">Services</a></li>
        </ul>
    </li>

    <!-- ------------------------------------------------- -->
    <!-- Documentations -->
    <!-- ------------------------------------------------- -->
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('tech.documentations.index') ? 'active' : '' }}" href="{{ route('tech.documentations.index') }}">Documentations</a>
    </li>

    <!-- ------------------------------------------------- -->
    <!-- Inbox -->
    <!-- ------------------------------------------------- -->
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('tech.inbox.index') ? 'active' : '' }}" href="{{ route('tech.inbox.index') }}">Inbox</a>
    </li>

    <!-- ------------------------------------------------- -->
    <!-- Knowledge Base -->
    <!-- ------------------------------------------------- -->
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('tech.knowledge.index') ? 'active' : '' }}" href="{{ route('tech.knowledge.index') }}">Knowledge Base</a>
    </li>

    <!-- ------------------------------------------------- -->
    <!-- Reports -->
    <!-- ------------------------------------------------- -->
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('tech.reports.index') ? 'active' : '' }}" href="{{ route('tech.reports.index') }}">Reports</a>
    </li>

    <!-- ------------------------------------------------- -->
    <!-- Sales -->
    <!-- ------------------------------------------------- -->
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('tech.sales.index') ? 'active' : '' }}" href="{{ route('tech.sales.index') }}">Sales</a>
    </li>

    <!-- ------------------------------------------------- -->
    <!-- Storage -->
    <!-- ------------------------------------------------- -->
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('tech.storage.index') ? 'active' : '' }}" href="{{ route('tech.storage.index') }}">Storage</a>
    </li>

    <!-- ------------------------------------------------- -->
    <!-- Tasks -->
    <!-- ------------------------------------------------- -->
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('tech.tasks.index') ? 'active' : '' }}" href="{{ route('tech.tasks.index') }}">Tasks</a>
    </li>

    <!-- ------------------------------------------------- -->
    <!-- Tickets -->
    <!-- ------------------------------------------------- -->
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('tech.tickets.index') ? 'active' : '' }}" href="{{ route('tech.tickets.index') }}">Tickets</a>
    </li>

</ul>