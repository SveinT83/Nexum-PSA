@extends('layouts.default_tech')

@section('title', 'TemplatesManagement Management')

@section('pageHeader')
    <h1>Templates Management</h1>
@endsection

@section('content')
    <h2>Welcome to the Templates Management</h2>
    <p>This is your central hub for managing templates and monitoring system status.</p>

    <div class="row">
        <div class="col-md-6">
            <x-card.default title="Documentation Templates">
                <p class="text-muted">Structured documentation form schemas.</p>
                <a href="{{ route('tech.admin.system.templatesManagement.doc.index') }}" class="btn btn-sm btn-outline-primary">Open</a>
            </x-card.default>
        </div>

        <div class="col-md-6">
            <x-card.default title="Email Templates">
                <p class="text-muted">Outbound email subjects and body templates for tickets, system notifications, and future workflows.</p>
                <a href="{{ route('tech.admin.system.templatesManagement.email.index') }}" class="btn btn-sm btn-outline-primary">Open</a>
            </x-card.default>
        </div>
    </div>
@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" />
    @endif
@endsection

@section('rightbar')
@endsection
