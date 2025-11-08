<!DOCTYPE html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ config('app.name','Laravel') }}</title>
  @vite(['resources/css/app.css','resources/js/app.js'])
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container-fluid">
    <!-- ----------------------------------------------------------------- -->
    <!-- Header -->
    <!-- ----------------------------------------------------------------- -->
    <div class="row p-3 bg-light-subtle border-bottom">
      <h3>Header</h3>
    </div>


    <!-- ----------------------------------------------------------------- -->
    <!-- Main Content -->
    <!-- ----------------------------------------------------------------- -->
    <div class="row">

      <!-- ------------------------------ -->
      <!-- Sidebar -->
      <!-- ------------------------------ -->
      <div class="col-md-2 border-end">@yield('sidebar')</div>

      <!-- ------------------------------ -->
      <!-- Content Area whith Page Header -->
      <!-- ------------------------------ -->
      <div class="col-md-7 overflow-auto bg-white">

          <!-- Page Header -->
          <div class="row mb-3 border-bottom border-light-subtle">@yield('pageheader')</div>

          <!-- Main Content -->
          @yield('content')
      </div>

      <!-- ------------------------------ -->
      <!-- Right Sidebar -->
      <!-- ------------------------------ -->
      <div class="col-md-3 border-start">@yield('rightbar')</div>
    </div>

    <!-- ----------------------------------------------------------------- -->
    <!-- Footer -->
    <!-- ----------------------------------------------------------------- -->
    <div class="row text-bg-dark border-top">
      <div class="col-12 text-center">
        <p>&copy; {{ date('Y') }} {{ config('app.name','Laravel') }}. All rights reserved.</p>
      </div>
    </div>

  </div>
</body>
</html>
