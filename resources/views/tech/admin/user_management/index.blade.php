@extends('layouts.default_tech')

@section('title', 'Users Management')

@section('pageHeader')
    <h1>Users Management</h1>
@endsection

@section('content')

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Users Card -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <div class="card">

        <!-- --------------------------------------------- -->
        <!-- Card header -->
        <!-- Card title and add button -->
        <!-- --------------------------------------------- -->
        <div class="card-header">
            <div class="row">
                <h4 class="col-10">Users</h4>

                <div class="col-2 text-right">
                    <x-buttons.addlink url="{{ route('tech.admin.user_management.create') }}"> New user </x-buttons.addlink>
                </div>
            </div>
        </div>

        <!-- --------------------------------------------- -->
        <!-- Card body -->
        <!-- List of users and they roles -->
        <!-- --------------------------------------------- -->
        <div class="card-body">
            <p>List of users whit role</p>
        </div>
    </div>
@endsection

@section('sidebar')
    <!-- Sidebar Menu Item -->
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" />
    @endif
@endsection

@section('rightbar')
    <h3>Notifications</h3>
    <ul>
        <li>No new notifications.</li>
    </ul>
@endsection
