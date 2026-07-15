@php
    $companyProfile = app(\App\Modules\System\Support\CompanyProfileSettings::class)->get();
    $companyName = $companyProfile['company_name'] ?? config('app.name', 'Nexum PSA');
    $brandLogoUrl = $companyProfile['logo_light_url'] ?? $companyProfile['logo_url'] ?? null;
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $companyName }}</title>
        @PwaHead
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            :root {
                --nexum-login-primary: {{ $companyProfile['primary_color'] }};
                --nexum-login-page-bg: {{ $companyProfile['light_main_background'] }};
                --nexum-login-card-bg: {{ $companyProfile['light_content_background'] }};
            }

            body {
                min-height: 100vh;
                background: var(--nexum-login-page-bg);
            }

            .login-shell {
                min-height: 100vh;
            }

            .login-card {
                max-width: 28rem;
                background: var(--nexum-login-card-bg);
            }

            .login-logo {
                max-height: 4rem;
                max-width: 14rem;
                object-fit: contain;
            }

            .btn-primary {
                --bs-btn-bg: {{ $companyProfile['light_primary_button_background'] }};
                --bs-btn-border-color: {{ $companyProfile['light_primary_button_background'] }};
                --bs-btn-color: {{ $companyProfile['light_primary_button_color'] }};
                --bs-btn-hover-bg: {{ $companyProfile['primary_color'] }};
                --bs-btn-hover-border-color: {{ $companyProfile['primary_color'] }};
            }
        </style>
    </head>
    <body>
        <main class="login-shell d-flex align-items-center py-5">
            <div class="container">
                <div class="card login-card mx-auto border-0 shadow-sm">
                    <div class="card-body p-4 p-sm-5">
                        <div class="text-center mb-4">
                            @if($brandLogoUrl)
                                <img src="{{ $brandLogoUrl }}" alt="{{ $companyName }} logo" class="login-logo mb-3">
                            @else
                                <div class="h4 mb-3">{{ $companyName }}</div>
                            @endif
                            <h1 class="h4 mb-1">Logg inn</h1>
                            <p class="text-muted mb-0">PSA workspace</p>
                        </div>

                        @if ($errors->any())
                            <div class="alert alert-danger">
                                @foreach ($errors->all() as $error)
                                    <div>{{ $error }}</div>
                                @endforeach
                            </div>
                        @endif

                        @if (session('status'))
                            <div class="alert alert-success">
                                {{ session('status') }}
                            </div>
                        @endif

                        <form action="/login" method="POST">
                            @csrf
                            <div class="mb-3">
                                <label for="email" class="form-label">E-postadresse</label>
                                <input id="email" type="email" name="email" class="form-control" placeholder="name@example.com" autocomplete="email" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Passord</label>
                                <input id="password" type="password" name="password" class="form-control" autocomplete="current-password" required>
                            </div>
                            <div class="form-check mb-4">
                                <input id="remember" name="remember" type="checkbox" class="form-check-input">
                                <label for="remember" class="form-check-label">Husk meg</label>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Logg inn</button>
                        </form>
                    </div>
                </div>
            </div>
        </main>
        @RegisterServiceWorkerScript
    </body>
</html>
