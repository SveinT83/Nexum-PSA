<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Nexum PSA') }}</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="d-flex flex-column min-vh-100">

        <!-- ------------------------------------------------- -->
        {{-- Header (logo, meny, profil, breadcrumbs) --}}
        <!-- ------------------------------------------------- -->
        <header class="sticky-top border-bottom">
            <div class="container-fluid bg-primarily pb-3">
                <div class="row">
                    <img class="col-1" src="" alt="Tech Dashboard Logo" class="my-3 mx-auto d-block" style="max-height: 80px;">

                    <div class="col-8">
                        @include('partials.nav.tech_nav')
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
                    <div class="col-md-2 bg-light">
                        <h1 class="bg-sidebar">Sidebar A</h1>
                        @yield('sidebar')
                    </div>

                    <!-- Main content (center) -->
                    <div class="col-md-8 bg-page-header border border-top-0">

                        <!-- Page header -->
                        <div class="row pb-4 bg-page-header border-bottom">
                            @yield('pageHeader')
                        </div>

                        <!-- Main content -->
                        <div class="row bg-page-content p-1">
                            <div class="container">
                                @yield('content')
                            </div>
                        </div>

                    </div>

                    <!-- Right sidebar (right) -->
                    <div class="col-md-2 bg-light">
                        <h1 class="bg-sidebar">Sidebar B</h1>
                        @yield('rightbar')
                    </div>
                </div>

            </div>

        </main>

        <!-- ------------------------------------------------- -->
        <!-- Footer -->
        <!-- ------------------------------------------------- -->
        <footer class="mt-auto bg-dark-subtle py-3">
            <div class="container-fluid text-center">
                <div class="row">
                    <p>&copy; 2024 Tech Dashboard. All rights reserved.</p>
                </div>
            </div>
        </footer>

        <!-- ------------------------------------------------- -->
        <!-- Bootstrap JS Bundle with Popper -->
        <!-- ------------------------------------------------- -->
        <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" integrity="sha384-G/EV+4j2dNv+tEPo3++6LCgdCROaejBqfUeNjuKAiuXbjrxilcCdDz6ZAVfHWe1Y" crossorigin="anonymous"></script>

    </body>
</html>
