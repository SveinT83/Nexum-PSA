<ul class="nav nav-tabs">
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('tech.dashboard') ? 'active' : '' }}" aria-current="page" href="{{ route('tech.dashboard') }}">Dashboard</a>
    </li>

    @php
        $adminGroupActive = request()->routeIs('tech.admin*');
    @endphp

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Admin dropdown menu -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <li class="nav-item">
        <a class="nav-link {{ $adminGroupActive ? 'active' : '' }}" href="{{ route('tech.admin.index') }}" role="button" aria-current="page">Admin</a>
    </li>


    <!-- ------------------------------------------------- -->
    <!-- Clients -->
    <!-- ------------------------------------------------- -->
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('tech.clients.index') ? 'active' : '' }}" href="{{ route('tech.clients.index') }}">Clients</a>
    </li>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Work dropdown menu -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    @php
        $workGroupActive = request()->routeIs('tech.risk*') || request()->routeIs('tech.inbox*') || request()->routeIs('tech.tasks*') || request()->routeIs('tech.tickets*');
    @endphp

    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle {{ $workGroupActive ? 'active' : '' }}" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">Work</a>
        <ul class="dropdown-menu">

            <!-- ------------------------------------------------- -->
            <!-- Risk -->
            <!-- ------------------------------------------------- -->
            <li class="nav-item">
                <a class="dropdown-item {{ request()->routeIs('tech.risk.index') ? 'active' : '' }}" href="{{ route('tech.risk.index', 'all') }}">Risk</a>
            </li>

            <!-- ------------------------------------------------- -->
            <!-- Inbox -->
            <!-- ------------------------------------------------- -->
            <li class="nav-item">
                <a class="dropdown-item {{ request()->routeIs('tech.inbox.index') ? 'active' : '' }}" href="{{ route('tech.inbox.index') }}">Inbox</a>
            </li>

            <!-- ------------------------------------------------- -->
            <!-- Tasks -->
            <!-- ------------------------------------------------- -->
            <li class="nav-item">
                <a class="dropdown-item {{ request()->routeIs('tech.tasks.index') ? 'active' : '' }}" href="{{ route('tech.tasks.index') }}">Tasks</a>
            </li>

            <!-- ------------------------------------------------- -->
            <!-- Tickets -->
            <!-- ------------------------------------------------- -->
            <li class="nav-item">
                <a class="dropdown-item {{ request()->routeIs('tech.tickets.index') ? 'active' : '' }}" href="{{ route('tech.tickets.index') }}">Tickets</a>
            </li>

        </ul>
    </li>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Knowledge dropdown menu -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    @php
        $knowledgeGroupActive = request()->routeIs('tech.documentations.index') || request()->routeIs('tech.knowledge*');
    @endphp

    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle {{ $knowledgeGroupActive ? 'active' : '' }}" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">Knowledge</a>
        <ul class="dropdown-menu">

            <!-- ------------------------------------------------- -->
            <!-- Documentations -->
            <!-- ------------------------------------------------- -->
            <li class="nav-item">
                <a class="dropdown-item {{ request()->routeIs('tech.documentations.index') ? 'active' : '' }}" href="{{ route('tech.documentations.index', 'all') }}">Documentations</a>
            </li>

            <!-- ------------------------------------------------- -->
            <!-- Knowledge Base -->
            <!-- ------------------------------------------------- -->
            <li class="nav-item">
                <a class="dropdown-item {{ request()->routeIs('tech.knowledge.index') ? 'active' : '' }}" href="{{ route('tech.knowledge.index') }}">Knowledge Base</a>
            </li>

        </ul>
    </li>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Contracts & Services dropdown menu -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    @php
        $contractsGroupActive = request()->routeIs('tech.contracts.*') || request()->routeIs('tech.services.*') || request()->routeIs('tech.packages.*') || request()->routeIs('tech.sales.*');
    @endphp

    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle {{ $contractsGroupActive ? 'active' : '' }}" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">Sales</a>
        <ul class="dropdown-menu">

            <!-- ------------------------------------------------- -->
            <!-- Sales -->
            <!-- ------------------------------------------------- -->
            <li class="nav-item">
                <a class="dropdown-item {{ request()->routeIs('tech.sales.index') ? 'active' : '' }}" href="{{ route('tech.sales.index') }}">Sales</a>
            </li>

            <!-- ------------------------------------------------- -->
            <!-- Contracts -->
            <!-- ------------------------------------------------- -->
            <li><a class="dropdown-item {{ request()->routeIs('tech.contracts.index') ? 'active' : '' }}" href="{{ route('tech.contracts.index') }}">Contracts</a></li>

            <!-- ------------------------------------------------- -->
            <!-- Packages -->
            <!-- ------------------------------------------------- -->
            <li><a class="dropdown-item {{ request()->routeIs('tech.packages.*') ? 'active' : '' }}" href="{{ route('tech.packages.index') }}">Packages</a></li>

            <!-- ------------------------------------------------- -->
            <!-- Services -->
            <!-- ------------------------------------------------- -->
            <li><a class="dropdown-item {{ request()->routeIs('tech.services.index') ? 'active' : '' }}" href="{{ route('tech.services.index') }}">Services</a></li>

            <!-- ------------------------------------------------- -->
            <!-- Costs -->
            <!-- ------------------------------------------------- -->
            <li><a class="dropdown-item {{ request()->routeIs('tech.costs.index') ? 'active' : '' }}" href="{{ route('tech.costs.index') }}">Costs</a></li>

            <!-- ------------------------------------------------- -->
            <!-- Legal - Legal & Terms -->
            <!-- ------------------------------------------------- -->
            <li><a class="dropdown-item {{ request()->routeIs('tech.legal.index') ? 'active' : '' }}" href="{{ route('tech.legal.index') }}">Legal & Terms</a></li>

            <!-- ------------------------------------------------- -->
            <!-- SLA -->
            <!-- ------------------------------------------------- -->
            <li><a class="dropdown-item {{ request()->routeIs('tech.sla.index') ? 'active' : '' }}" href="{{ route('tech.sla.index') }}">SLA</a></li>
        </ul>
    </li>

    <!-- ------------------------------------------------- -->
    <!-- Reports -->
    <!-- ------------------------------------------------- -->
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('tech.reports.index') ? 'active' : '' }}" href="{{ route('tech.reports.index') }}">Reports</a>
    </li>

    <!-- ------------------------------------------------- -->
    <!-- Storage -->
    <!-- ------------------------------------------------- -->
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('tech.storage.index') ? 'active' : '' }}" href="{{ route('tech.storage.index') }}">Storage</a>
    </li>

</ul>
