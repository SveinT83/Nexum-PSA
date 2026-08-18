<x-nav.work-menu />

<!-- ------------------------------------------------- -->
<!-- Mail Access Navigation -->
<!-- ------------------------------------------------- -->
<nav class="border-top pt-3 pb-3" aria-label="Mail access navigation">
    <div class="px-2 mb-2">
        <div class="small text-uppercase fw-semibold text-muted">Mail access</div>
    </div>
    <div class="nav nav-pills flex-column gap-1">
        <a
            href="{{ route('tech.mail.index') }}"
            class="nav-link d-flex align-items-center gap-2 px-3 py-2 link-dark bg-light border">
            <i class="bi bi-inbox" aria-hidden="true"></i>
            <span>Mail</span>
        </a>
        <a
            href="{{ route('tech.mail.access.index') }}"
            class="nav-link d-flex align-items-center gap-2 px-3 py-2 {{ request()->routeIs('tech.mail.access.index') ? 'active' : 'link-dark bg-light border' }}"
            @if(request()->routeIs('tech.mail.access.index')) aria-current="page" @endif>
            <i class="bi bi-person-lock" aria-hidden="true"></i>
            <span>Mailbox access</span>
        </a>
        <a
            href="{{ route('tech.mail.access.history') }}"
            class="nav-link d-flex align-items-center gap-2 px-3 py-2 {{ request()->routeIs('tech.mail.access.history') ? 'active' : 'link-dark bg-light border' }}"
            @if(request()->routeIs('tech.mail.access.history')) aria-current="page" @endif>
            <i class="bi bi-clock-history" aria-hidden="true"></i>
            <span>Access history</span>
        </a>
    </div>
</nav>
