@php
    $isMobileNav = $mobile ?? false;
    $navClass = $isMobileNav ? 'nav nav-pills flex-column gap-1 w-100' : 'nav nav-tabs align-items-center flex-wrap';
    $dropdownMenuClass = $isMobileNav ? 'dropdown-menu position-static show border-0 shadow-none ps-3' : 'dropdown-menu';
    $profileDropdownMenuClass = $isMobileNav ? $dropdownMenuClass : 'dropdown-menu dropdown-menu-end';
    $logoutFormId = $isMobileNav ? 'logout-form-mobile' : 'logout-form';
@endphp

<ul class="{{ $navClass }}">
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('tech.dashboard') ? 'active' : '' }}" aria-current="page" href="{{ route('tech.dashboard') }}">Dashboard</a>
    </li>

    @if(Route::has('tech.my-day.index'))
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('tech.my-day.*') ? 'active' : '' }}" aria-current="page" href="{{ route('tech.my-day.index') }}">My Day</a>
        </li>
    @endif

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
        <a class="nav-link dropdown-toggle {{ $clientsGroupActive ? 'active' : '' }}" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="{{ $isMobileNav ? 'true' : 'false' }}">Clients</a>
        <ul class="{{ $dropdownMenuClass }}">
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
        $workGroupActive = request()->routeIs('tech.risk*') || request()->routeIs('tech.inbox*') || request()->routeIs('tech.tasks*') || request()->routeIs('tech.tickets*') || request()->routeIs('tech.telephony*') || request()->routeIs('tech.assets*') || request()->routeIs('tech.calendar*');
    @endphp

    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle {{ $workGroupActive ? 'active' : '' }}" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="{{ $isMobileNav ? 'true' : 'false' }}">Work</a>
        <ul class="{{ $dropdownMenuClass }}">

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

            @if(Route::has('tech.telephony.profile'))
                <li class="nav-item">
                    <a class="dropdown-item {{ request()->routeIs('tech.telephony*') ? 'active' : '' }}" href="{{ route('tech.telephony.profile') }}">Telephony</a>
                </li>
            @endif

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
        <a class="nav-link dropdown-toggle {{ $knowledgeGroupActive ? 'active' : '' }}" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="{{ $isMobileNav ? 'true' : 'false' }}">Knowledge</a>
        <ul class="{{ $dropdownMenuClass }}">

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
        <a class="nav-link dropdown-toggle {{ $contractsGroupActive ? 'active' : '' }}" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="{{ $isMobileNav ? 'true' : 'false' }}">Sales</a>
        <ul class="{{ $dropdownMenuClass }}">

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
        <a class="nav-link dropdown-toggle {{ $economyGroupActive ? 'active' : '' }}" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="{{ $isMobileNav ? 'true' : 'false' }}">Economy</a>
        <ul class="{{ $dropdownMenuClass }}">

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
        <a class="nav-link dropdown-toggle {{ $storageGroupActive ? 'active' : '' }}" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="{{ $isMobileNav ? 'true' : 'false' }}">Storage</a>
        <ul class="{{ $dropdownMenuClass }}">
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
        <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="{{ $isMobileNav ? 'true' : 'false' }}">
            <i class="bi bi-person-circle me-1"></i>{{ auth()->user()->name ?? 'User' }}
        </a>
        <ul class="{{ $profileDropdownMenuClass }}">
            <li><a class="dropdown-item" href="{{ route('tech.profile.index') }}"><i class="bi bi-person-badge me-2"></i>Profile</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('{{ $logoutFormId }}').submit();"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
        </ul>
    </li>

    <li class="nav-item d-flex align-items-center">
        {{-- Notification bell --}}
        <div class="d-flex align-items-center px-2">
            <livewire:notification-bell />
        </div>
    </li>

    <form id="{{ $logoutFormId }}" action="{{ route('logout') }}" method="POST" class="d-none">@csrf</form>

</ul>
