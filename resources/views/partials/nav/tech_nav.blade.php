<ul class="nav nav-tabs align-items-center">
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


    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Clients dropdown menu -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    @php
        $clientsGroupActive = request()->routeIs('tech.clients*') || request()->routeIs('tech.client*') || request()->routeIs('tech.contacts*');
    @endphp

    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle {{ $clientsGroupActive ? 'active' : '' }}" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">Clients</a>
        <ul class="dropdown-menu">
            <li>
                <a class="dropdown-item {{ request()->routeIs('tech.clients.index') ? 'active' : '' }}" href="{{ route('tech.clients.index') }}">Clients</a>
            </li>
            <li>
                <a class="dropdown-item {{ request()->routeIs('tech.clients.sites.*') ? 'active' : '' }}" href="{{ route('tech.clients.sites.index') }}">Sites</a>
            </li>
            @if(Route::has('tech.contacts.index'))
                <li>
                    <a class="dropdown-item {{ request()->routeIs('tech.contacts*') ? 'active' : '' }}" href="{{ route('tech.contacts.index') }}">Contacts</a>
                </li>
            @endif
        </ul>
    </li>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Work dropdown menu -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    @php
        $workGroupActive = request()->routeIs('tech.risk*') || request()->routeIs('tech.inbox*') || request()->routeIs('tech.tasks*') || request()->routeIs('tech.tickets*') || request()->routeIs('tech.assets*') || request()->routeIs('tech.calendar*');
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

            <!-- ------------------------------------------------- -->
            <!-- Assets -->
            <!-- ------------------------------------------------- -->
            <li class="nav-item">
                <a class="dropdown-item {{ request()->routeIs('tech.assets.index') ? 'active' : '' }}" href="{{ route('tech.assets.index') }}">Assets</a>
            </li>

            <!-- ------------------------------------------------- -->
            <!-- Calendar -->
            <!-- ------------------------------------------------- -->
            @if(Route::has('tech.calendar.index'))
                <li class="nav-item">
                    <a class="dropdown-item {{ request()->routeIs('tech.calendar*') ? 'active' : '' }}" href="{{ route('tech.calendar.index') }}">Calendar</a>
                </li>
            @endif

        </ul>
    </li>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Knowledge dropdown menu -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    @php
        $knowledgeGroupActive = request()->routeIs('tech.documentations.index') || request()->routeIs('tech.knowledge*') || request()->routeIs('tech.ai.chats.*');
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

            @if(Route::has('tech.ai.chats.index'))
                <!-- ------------------------------------------------- -->
                <!-- AI Chats -->
                <!-- ------------------------------------------------- -->
                <li class="nav-item">
                    <a class="dropdown-item {{ request()->routeIs('tech.ai.chats.*') ? 'active' : '' }}" href="{{ route('tech.ai.chats.index') }}">AI Chats</a>
                </li>
            @endif

        </ul>
    </li>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Contracts & Services dropdown menu -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    @php
        $contractsGroupActive = request()->routeIs('tech.contracts.*')
            || request()->routeIs('tech.services.*')
            || request()->routeIs('tech.packages.*')
            || request()->routeIs('tech.sales.*')
            || request()->routeIs('tech.lead-intelligence.*')
            || request()->routeIs('tech.marketing.*')
            || request()->routeIs('tech.rates.*');
        $economyGroupActive = request()->routeIs('tech.economy.*');
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

            @if(Route::has('tech.sales.leads.index'))
                <li class="nav-item">
                    <a class="dropdown-item {{ request()->routeIs('tech.sales.leads.*') ? 'active' : '' }}" href="{{ route('tech.sales.leads.index') }}">Leads</a>
                </li>
            @endif

            @if(Route::has('tech.lead-intelligence.segments.index'))
                <li class="nav-item">
                    <a class="dropdown-item {{ request()->routeIs('tech.lead-intelligence.*') ? 'active' : '' }}" href="{{ route('tech.lead-intelligence.segments.index') }}">Lead Intelligence</a>
                </li>
            @endif

            @if(Route::has('tech.marketing.index'))
                <li class="nav-item">
                    <a class="dropdown-item {{ request()->routeIs('tech.marketing.*') ? 'active' : '' }}" href="{{ route('tech.marketing.index') }}">Marketing</a>
                </li>
            @endif

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
            <!-- Rates -->
            <!-- ------------------------------------------------- -->
            @if(Route::has('tech.rates.index'))
                <li><a class="dropdown-item {{ request()->routeIs('tech.rates.*') ? 'active' : '' }}" href="{{ route('tech.rates.index') }}">Rates</a></li>
            @endif

            <!-- ------------------------------------------------- -->
            <!-- SLA -->
            <!-- ------------------------------------------------- -->
            <li><a class="dropdown-item {{ request()->routeIs('tech.sla.index') ? 'active' : '' }}" href="{{ route('tech.sla.index') }}">SLA</a></li>
        </ul>
    </li>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Economy dropdown menu -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle {{ $economyGroupActive ? 'active' : '' }}" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">Economy</a>
        <ul class="dropdown-menu">

            <!-- ------------------------------------------------- -->
            <!-- Orders -->
            <!-- ------------------------------------------------- -->
            @if(Route::has('tech.economy.orders.index'))
                <li><a class="dropdown-item {{ request()->routeIs('tech.economy.orders.*') ? 'active' : '' }}" href="{{ route('tech.economy.orders.index') }}">Orders</a></li>
            @endif
        </ul>
    </li>

    <!-- ------------------------------------------------- -->
    <!-- Reports -->
    <!-- ------------------------------------------------- -->
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('tech.reports.index') ? 'active' : '' }}" href="{{ route('tech.reports.index') }}">Reports</a>
    </li>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Storage dropdown menu -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    @php
        $storageGroupActive = request()->routeIs('tech.storage*');
    @endphp

    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle {{ $storageGroupActive ? 'active' : '' }}" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">Storage</a>
        <ul class="dropdown-menu">
            <li>
                <a class="dropdown-item {{ request()->routeIs('tech.storage.index') ? 'active' : '' }}" href="{{ route('tech.storage.index') }}">Inventory</a>
            </li>
            @if(Route::has('tech.storage.picking'))
                <li>
                    <a class="dropdown-item {{ request()->routeIs('tech.storage.picking*') ? 'active' : '' }}" href="{{ route('tech.storage.picking') }}">Picking List</a>
                </li>
            @endif
        </ul>
    </li>

    <!-- ------------------------------------------------- -->
    <!-- Profile dropdown -->
    <!-- ------------------------------------------------- -->
    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">
            <i class="bi bi-person-circle me-1"></i>{{ auth()->user()->name ?? 'User' }}
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="{{ route('tech.profile.index') }}"><i class="bi bi-person-badge me-2"></i>Profile</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
        </ul>
    </li>

    <li class="nav-item d-flex align-items-center">
        {{-- Notification bell --}}
        <div class="d-flex align-items-center px-2">
            <livewire:notification-bell />
        </div>
    </li>

    <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">@csrf</form>

</ul>
