@php
    $companyProfile = $companyProfile ?? app(\App\Modules\System\Support\CompanyProfileSettings::class)->get();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', $companyProfile['company_name'] ?? config('app.name', 'Nexum PSA'))</title>
    @PwaHead
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <style>
        :root {
            --bs-primary: {{ $companyProfile['primary_color'] ?? '#ff6d1f' }};
            --bs-link-color: {{ $companyProfile['primary_color'] ?? '#ff6d1f' }};
            --bs-link-hover-color: {{ $companyProfile['accent_color'] ?? '#cc4f0f' }};
        }

        .booking-shell {
            min-height: 100vh;
            background: var(--bs-tertiary-bg);
        }

        .booking-brand-mark {
            width: 2.25rem;
            height: 2.25rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--bs-border-color);
            border-radius: .5rem;
            color: var(--bs-primary);
            background: var(--bs-body-bg);
        }

        .booking-honeypot {
            position: absolute;
            left: -10000px;
            top: auto;
            width: 1px;
            height: 1px;
            overflow: hidden;
        }
    </style>
</head>
<body>
    <main class="booking-shell py-4">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-xl-8 col-lg-9">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="booking-brand-mark" aria-hidden="true">
                            <i class="bi bi-calendar-check"></i>
                        </span>
                        <div>
                            <div class="fw-semibold">{{ $companyProfile['company_name'] ?? config('app.name', 'Nexum PSA') }}</div>
                            <div class="small text-muted">@yield('eyebrow', 'Booking')</div>
                        </div>
                    </div>

                    @if($errors->any())
                        <div class="alert alert-danger" role="alert">
                            <ul class="mb-0">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @yield('content')
                </div>
            </div>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    @yield('scripts')
    @RegisterServiceWorkerScript
</body>
</html>
