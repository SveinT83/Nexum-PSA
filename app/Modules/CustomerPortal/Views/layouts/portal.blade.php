@php
    $companyProfile = app(\App\Modules\System\Support\CompanyProfileSettings::class)->get();
    $brandLogoUrl = $companyProfile['logo_light_url'] ?? $companyProfile['logo_url'] ?? null;
    $portalUnreadCount = auth()->check()
        ? auth()->user()->unreadNotifications()->where('type', \App\Modules\Notification\Notifications\CustomerPortalNotification::class)->count()
        : 0;
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>@yield('title', 'Customer Portal') - {{ $companyProfile['company_name'] ?? config('app.name', 'Nexum PSA') }}</title>
        @PwaHead
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
        <style>
            :root {
                --portal-primary: {{ $companyProfile['primary_color'] ?? '#FF6D1F' }};
                --portal-secondary: {{ $companyProfile['secondary_color'] ?? '#fc7730' }};
                --portal-bg: {{ $companyProfile['light_main_background'] ?? '#f6f7f9' }};
                --portal-content-bg: {{ $companyProfile['light_content_background'] ?? '#ffffff' }};
            }

            body {
                background: var(--portal-bg);
            }

            .portal-shell {
                min-height: 100vh;
            }

            .portal-brand-logo {
                max-height: 2.25rem;
                max-width: 12rem;
                object-fit: contain;
            }

            .portal-brand-mark {
                width: 2.25rem;
                height: 2.25rem;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border: 1px solid var(--bs-border-color);
                border-radius: .5rem;
                color: var(--portal-primary);
                background: var(--bs-tertiary-bg);
            }

            .btn-primary {
                --bs-btn-bg: var(--portal-primary);
                --bs-btn-border-color: var(--portal-primary);
                --bs-btn-hover-bg: var(--portal-secondary);
                --bs-btn-hover-border-color: var(--portal-secondary);
            }

            .portal-nav {
                flex-wrap: wrap;
                justify-content: flex-end;
            }
        </style>
    </head>
    <body>
        <div class="portal-shell d-flex flex-column">
            <header class="border-bottom bg-body">
                <div class="container py-3 d-flex align-items-center justify-content-between gap-3">
                    <a href="{{ Route::has('customer-portal.dashboard') ? route('customer-portal.dashboard') : url('/') }}" class="d-flex align-items-center gap-2 text-decoration-none text-body">
                        @if($brandLogoUrl)
                            <img src="{{ $brandLogoUrl }}" alt="" class="portal-brand-logo">
                            <span class="visually-hidden">{{ $companyProfile['company_name'] }}</span>
                        @else
                            <span class="portal-brand-mark" aria-hidden="true"><i class="bi bi-buildings"></i></span>
                            <span class="fw-semibold">{{ $companyProfile['company_name'] ?? config('app.name', 'Nexum PSA') }}</span>
                        @endif
                    </a>

                    @auth
                        <div class="d-flex align-items-center gap-2 portal-nav">
                            @if(Route::has('customer-portal.dashboard'))
                                <a href="{{ route('customer-portal.dashboard') }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-grid me-1" aria-hidden="true"></i>
                                    Dashboard
                                </a>
                            @endif
                            @if(Route::has('customer-portal.tickets.index'))
                                <a href="{{ route('customer-portal.tickets.index') }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-ticket-detailed me-1" aria-hidden="true"></i>
                                    Tickets
                                </a>
                            @endif
                            @if(Route::has('customer-portal.documents.index'))
                                <a href="{{ route('customer-portal.documents.index') }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-folder2-open me-1" aria-hidden="true"></i>
                                    Documents
                                </a>
                            @endif
                            @if(Route::has('customer-portal.knowledge.index'))
                                <a href="{{ route('customer-portal.knowledge.index') }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-journal-text me-1" aria-hidden="true"></i>
                                    Knowledge
                                </a>
                            @endif
                            @if(Route::has('customer-portal.quotes.index'))
                                <a href="{{ route('customer-portal.quotes.index') }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-file-earmark-check me-1" aria-hidden="true"></i>
                                    Quotes
                                </a>
                            @endif
                            @if(Route::has('customer-portal.contracts.index'))
                                <a href="{{ route('customer-portal.contracts.index') }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>
                                    Contracts
                                </a>
                            @endif
                            @if(Route::has('customer-portal.licenses.index')
                                && isset($context)
                                && $context->site === null
                                && $context->membership->role === \App\Modules\CustomerPortal\Models\CustomerPortalMembership::ROLE_CUSTOMER_ADMIN)
                                <a href="{{ route('customer-portal.licenses.index') }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-key me-1" aria-hidden="true"></i>
                                    Licences
                                </a>
                            @endif
                            @if(Route::has('customer-portal.orders.index'))
                                <a href="{{ route('customer-portal.orders.index') }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-receipt me-1" aria-hidden="true"></i>
                                    Orders
                                </a>
                            @endif
                            @if(Route::has('customer-portal.notifications.index'))
                                <a href="{{ route('customer-portal.notifications.index') }}" class="btn btn-sm btn-outline-secondary position-relative" aria-label="Notifications">
                                    <i class="bi bi-bell me-1" aria-hidden="true"></i>
                                    Notifications
                                    @if($portalUnreadCount > 0)
                                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                            {{ $portalUnreadCount > 9 ? '9+' : $portalUnreadCount }}
                                        </span>
                                    @endif
                                </a>
                            @endif
                            <form method="POST" action="{{ route('logout') }}" class="mb-0">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>
                                    Sign out
                                </button>
                            </form>
                        </div>
                    @endauth
                </div>
            </header>

            <main class="flex-grow-1">
                <div class="container py-4">
                    @foreach(['success' => 'success', 'status' => 'success', 'warning' => 'warning', 'info' => 'info', 'error' => 'danger'] as $flashKey => $flashType)
                        @if(session($flashKey))
                            <div class="alert alert-{{ $flashType }} alert-dismissible fade show" role="alert">
                                {{ session($flashKey) }}
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        @endif
                    @endforeach

                    @if($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            {{ $errors->first() }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif

                    @yield('content')
                </div>
            </main>

            <footer class="border-top bg-body">
                <div class="container py-3 small text-muted">
                    {{ $companyProfile['company_name'] ?? config('app.name', 'Nexum PSA') }}
                </div>
            </footer>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
        @RegisterServiceWorkerScript
    </body>
</html>
