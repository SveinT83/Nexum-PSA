@extends('layouts.default_tech')

@section('title', 'AI Integration')

@section('pageHeader')
    <div class="row">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('tech.admin.index') }}">Admin</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tech.admin.system.integrations.index') }}">Integrations</a></li>
                    <li class="breadcrumb-item active" aria-current="page">AI Integration</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center justify-content-between">
                <h1 class="h3 mb-0">AI Integration</h1>
                <div class="btn-group">
                    <a href="{{ route('tech.admin.system.integrations.ai.telemetry.index') }}" class="btn btn-outline-secondary">
                        <i class="fas fa-chart-line mr-1"></i> Telemetry
                    </a>
                    <a href="{{ route('tech.admin.system.integrations.ai.rate-cards.index') }}" class="btn btn-outline-secondary">
                        <i class="fas fa-list mr-1"></i> Rate Cards
                    </a>
                    <a href="{{ route('tech.admin.system.integrations.ai.index') }}" class="btn btn-outline-primary">
                        <i class="fas fa-cog mr-1"></i> AI Settings
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        @livewire('tech.admin.system.integrations.ai-settings')
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection

@section('rightbar')

@endsection
