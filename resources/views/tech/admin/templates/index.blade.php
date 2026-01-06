@extends('layouts.default_tech')

@section('title', 'Templates Management')

@section('pageHeader')
    <h1>Templates Management</h1>
@endsection

@section('content')
    <h2>Welcome to the Templates Management</h2>
    <p>This is your central hub for managing templates and monitoring system status.</p>
@endsection

@section('sidebar')
    <h3>Tech Sidebar</h3>
    <ul>
        <li><a href="#">System Status</a></li>
        <li><a href="#">Task Management</a></li>
        <li><a href="#">Reports</a></li>
    </ul>
@endsection

@section('rightbar')
    <h3>Notifications</h3>
    <ul>
        <li>No new notifications.</li>
    </ul>
@endsection