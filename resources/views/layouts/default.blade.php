<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Nexum PSA') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    </head>
    <body class="d-flex flex-column min-vh-100 bg-light">

        <!-- ------------------------------------------------- -->
        {{-- Header (logo, meny, profil, breadcrumbs) --}}
        <!-- ------------------------------------------------- -->
        <header class="sticky-top border-bottom">
            <div class="container-fluid">
                <div class="row mb-5">
                    <h2>Header</h2>

                    

                    <ul class="nav nav-tabs">
                        <li class="nav-item">
                            <a class="nav-link active" aria-current="page" href="#">Active</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#">Link</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#">Link</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link disabled" aria-disabled="true">Disabled</a>
                        </li>
                    </ul>

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
                    <div class="col-md-2 bg-light-subtle">
                        @yield('sidebar')
                    </div>

                    <!-- Main content (center) -->
                    <div class="col-md-8 bg-white border border-top-0">

                        <!-- Page header -->
                        <div class="row mb-4 border-bottom border-light-subtle">
                            @yield('pageHeader')
                        </div>

                        <!-- Main content -->
                        <div class="row">
                            @yield('content')
                        </div>

                    </div>

                    <!-- Right sidebar (right) -->
                    <div class="col-md-2 bg-light-subtle">
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

    </body>
</html>