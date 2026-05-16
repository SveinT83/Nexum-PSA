@extends('layouts.default_tech')

@section('title', 'AI Integration')

@section('pageHeader')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('tech.admin.system.integrations.index') }}">Integrations</a></li>
            <li class="breadcrumb-item active" aria-current="page">AI Integration</li>
        </ol>
    </nav>
    <h1>AI Integration</h1>
@endsection

@section('content')
    <div class="container-fluid">
        @livewire('tech.admin.system.integrations.ai-settings')
    </div>
@endsection

@section('sidebar')

@endsection

@section('rightbar')

@endsection
