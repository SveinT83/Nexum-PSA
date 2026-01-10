<!-- ------------------------------------------------- -->
<!-- Economy Dashboard -->
<!-- ------------------------------------------------- -->

@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">Economy</h2>
    </div>
@endsection

@section('content')



@endsection

@section('sidebar')

    <!-- ------------------------------------------------- -->
    <!-- Show sidebar menu if there are any items -->
    <!-- ------------------------------------------------- -->
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" />
    @endif

@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent Packages (MVP later)</div>
@endsection

