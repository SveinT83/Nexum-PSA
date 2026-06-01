{{--
    Main Layout for Tech Administration
    This layout provides the standard shell for all tech admin pages,
    including header, navigation, sidebar, and breadcrumbs.
--}}
@php
    $companyProfile = $companyProfile ?? app(\App\Modules\System\Support\CompanyProfileSettings::class)->get();
    $themePreference = auth()->check()
        ? data_get(auth()->user()->preferences()->first()?->settings, 'theme', 'system')
        : 'system';
    $themeAttribute = in_array($themePreference, ['light', 'dark'], true) ? $themePreference : null;
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if($themeAttribute) data-bs-theme="{{ $themeAttribute }}" @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $companyProfile['company_name'] ?? config('app.name', 'Nexum PSA') }}</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            :root {
                --nexum-brand-primary: {{ $companyProfile['primary_color'] }};
                --nexum-brand-secondary: {{ $companyProfile['secondary_color'] }};
                --nexum-brand-accent: {{ $companyProfile['accent_color'] }};
                --bs-primary: {{ $companyProfile['primary_color'] }};
                --bs-secondary: {{ $companyProfile['secondary_color'] }};
                --bs-link-color: {{ $companyProfile['primary_color'] }};
                --bs-link-hover-color: {{ $companyProfile['accent_color'] }};
            }

            .tech-shell-brand {
                color: var(--bs-body-color);
                min-height: 2.5rem;
            }

            .tech-shell-brand:hover,
            .tech-shell-brand:focus {
                color: var(--nexum-brand-primary);
            }

            .tech-shell-brand-logo {
                max-height: 2.25rem;
                max-width: 10rem;
                object-fit: contain;
            }

            .tech-shell-brand-mark {
                width: 2.25rem;
                height: 2.25rem;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border: 1px solid var(--bs-border-color);
                border-radius: .5rem;
                color: var(--nexum-brand-primary);
                background: var(--bs-tertiary-bg);
            }

            .page-header {
                border-bottom-color: var(--nexum-brand-primary) !important;
            }
        </style>
        @livewireStyles
    </head>
    <body class="d-flex flex-column min-vh-100">

        <!-- ------------------------------------------------- -->
        {{-- Header (logo, meny, profil, breadcrumbs) --}}
        <!-- ------------------------------------------------- -->
        <header class="sticky-top">
            <div class="container-fluid pb-3">
                <div class="row align-items-center g-2">
                    <div class="col-auto">
                        <a href="{{ route('tech.dashboard') }}" class="tech-shell-brand d-inline-flex align-items-center gap-2 text-decoration-none py-2">
                            @if($companyProfile['logo_url'])
                                <img src="{{ $companyProfile['logo_url'] }}" alt="{{ $companyProfile['company_name'] }} logo" class="tech-shell-brand-logo">
                            @else
                                <span class="tech-shell-brand-mark" aria-hidden="true">
                                    <i class="bi bi-buildings"></i>
                                </span>
                            @endif
                            <span class="fw-semibold">{{ $companyProfile['company_name'] }}</span>
                        </a>
                    </div>

                    <div class="col">
                        @include('partials.nav.tech_nav')

                        {{-- Notification bell --}}
                        <div class="float-end me-3 mt-2">
                            <livewire:notification-bell />
                        </div>
                    </div>

                </div>
            </div>
        </header>

        {{-- resources/views/components/shell.blade.php --}}
        <main class="flex-grow-1">
            <div class="container-fluid">

                <!-- ------------------------------------------------- -->
                <!-- Main grid: sidebar (left) • content (center) • rightbar (right) -->
                <!-- ------------------------------------------------- -->
                <div class="row">

                    <!-- Sidebar (left) -->
                    <div class="col-md-2 sidebar">

                        <!-- ------------------------------------------------- -->
                        <!-- More sidebar content from page file -->
                        <!-- ------------------------------------------------- -->
                        @yield('sidebar')
                    </div>

                    <!-- Main content (center) -->
                    <div class="col-md-8 border-start border-end">

                        <!-- Page header -->
                        <div class="row page-header py-2 align-items-center justify-content-between border-bottom border-primary">
                            @yield('pageHeader')

                            {{--
                                Breadcrumbs are automatically generated based on the current route name.
                                See config/breadcrumbs.php for definitions and app/Helpers/helpers.php
                                for the logic.
                            --}}
                            @include('partials.breadcrumbs')
                        </div>

                        <!-- Main content -->
                        <div class="row content p-1">
                            <div class="container pt-3">

                                @foreach(['status' => 'success', 'success' => 'success', 'warning' => 'warning', 'info' => 'info', 'error' => 'danger'] as $flashKey => $flashType)
                                    @if($flashKey === 'status' && in_array(session('status'), ['two-factor-enabled', 'two-factor-confirmed', 'two-factor-disabled', 'recovery-codes-regenerated', 'password-updated'], true))
                                        @continue
                                    @endif

                                    @if(session($flashKey))
                                        <div class="alert alert-{{ $flashType }} alert-dismissible fade show" role="alert">
                                            {!! session($flashKey) !!}
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
                        </div>

                    </div>

                    <!-- Right sidebar (right) -->
                    <div class="col-md-2 mt-3 sidebar">
                        @yield('rightbar')

                        @auth
                            <livewire:tech.ai.context-chat :page-title="trim($__env->yieldContent('title'))" />
                        @endauth
                    </div>
                </div>

            </div>

            {{-- Hidden Livewire synchronization components for RMM --}}
            <livewire:tech.admin.system.integrations.n-able-rmm-sync />
            <livewire:tech.admin.system.integrations.tactical-rmm-sync />
            <livewire:tech.work.assets.alerts.alert-sync-processor />
        </main>

        <!-- ------------------------------------------------- -->
        <!-- Footer -->
        <!-- ------------------------------------------------- -->
        <footer class="mt-auto py-3">
            <div class="container-fluid text-center">
                <div class="row gy-2">
                    <p class="mb-0">&copy; {{ date('Y') }} Nexum PSA. All rights reserved.</p>
                    <div class="small d-flex flex-wrap justify-content-center gap-3">
                        <a href="https://github.com/SveinT83/Nexum-PSA/issues"
                           class="link-secondary text-decoration-none"
                           target="_blank"
                           rel="noopener noreferrer">
                            <i class="bi bi-bug me-1" aria-hidden="true"></i>
                            Report issue
                        </a>
                        <a href="https://github.com/SveinT83/Nexum-PSA/discussions"
                           class="link-secondary text-decoration-none"
                           target="_blank"
                           rel="noopener noreferrer">
                            <i class="bi bi-lightbulb me-1" aria-hidden="true"></i>
                            Share ideas
                        </a>
                    </div>
                </div>
            </div>
        </footer>

        <!-- ------------------------------------------------- -->
        <!-- Bootstrap JS Bundle with Popper -->
        <!-- ------------------------------------------------- -->
        <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" integrity="sha384-G/EV+4j2dNv+tEPo3++6LCgdCROaejBqfUeNjuKAiuXbjrxilcCdDz6ZAVfHWe1Y" crossorigin="anonymous"></script>

        @livewireScripts
        @yield('scripts')
    </body>
</html>
