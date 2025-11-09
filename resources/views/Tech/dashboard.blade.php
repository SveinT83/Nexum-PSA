@extends('layouts.default')

@section('title', 'Tech Dashboard')

@section('pageHeader')
    <h1>Tech Dashboard</h1>
@endsection

@section('content')
    <h2>Welcome to the Tech Dashboard</h2>
    <p>This is your central hub for managing technical tasks and monitoring system status.</p>
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