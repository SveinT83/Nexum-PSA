@extends('layouts.default_tech')

@section('title', 'TemplatesManagement Management')

@section('pageHeader')
    <h1>Templates Management</h1>
@endsection

@section('content')
    <h2>Welcome to the Templates Management</h2>
    <p>This is your central hub for managing templates and monitoring system status.</p>
@endsection

@section('sidebar')
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
