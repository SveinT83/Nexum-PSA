@extends('layouts.default_tech')

@section('title', 'Users Management')

@section('pageHeader')
    <h1>Users Management</h1>
@endsection

@section('content')

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
