<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Laravel') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    </head>
    <body>

        {{-- resources/views/components/shell.blade.php --}}
        <div class="container-fluid">

            {{-- Header (logo, meny, profil, breadcrumbs) --}}
            <div class="row text-center mb-5">
                {{ $header ?? '' }}
            </div>

            {{-- Page header / tittelrad (valgfri) --}}
            @isset($pageheader)
                <div class="row mb-3">
                    {{ $pageheader }}
                </div>
            @endisset

            {{-- Hovedgrid: sidebar (venstre) • content (midt) • rightbar (høyre) --}}
            <div class="row">
                <div class="col-md-3">
                    {{ $sidebar ?? '' }}
                </div>

                <div class="col-md-6">
                    {{ $slot }} {{-- default slot = hovedinnhold --}}
                </div>

                <div class="col-md-3">
                    {{ $rightbar ?? '' }}
                </div>
            </div>
        </div>


    </body>
</html>