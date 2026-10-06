@extends('layouts.default_tech')
@section('title', 'Tripletex')
@section('pageHeader')
    <h1 class="h3">Tripletex connection</h1>
@endsection
@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection
@section('content')
    {{-- Connection status: read verification is deliberately separate from time transfer. --}}
    <p>This Nexum installation uses one Tripletex account. Time transfer is not activated by saving or verifying a connection.</p>
    @if($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif
    @if(session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    @foreach($connections as $connection)
        <section class="card mb-3">
            <div class="card-header d-flex justify-content-between">
                <span>{{ $connection->name }}</span>
                <span>{{ $connection->config['read_verified_at'] ?? 'Not verified' }}</span>
            </div>
            <div class="card-body">
                @include('integration::Tech.Admin.System.Integrations.tripletex.form', ['connection' => $connection])
                <div class="d-flex gap-2 mt-3">
                    <form method="post" action="{{ route('tech.admin.system.integrations.tripletex.verify', $connection->id) }}">
                        @csrf
                        <button class="btn btn-outline-secondary" type="submit">Verify company</button>
                    </form>
                    <form method="post" action="{{ route('tech.admin.system.integrations.tripletex.candidates', $connection->id) }}">
                        @csrf
                        <button class="btn btn-outline-secondary" type="submit">Read employees and activities</button>
                    </form>
                </div>
            </div>
        </section>
        @include('integration::Tech.Admin.System.Integrations.tripletex.time-sync')
    @endforeach
    {{-- First setup only; the server also rejects additional connections. --}}
    @if($connections->isEmpty())
    <section class="card">
        <div class="card-header">Set up Tripletex</div>
        <div class="card-body">
            @include('integration::Tech.Admin.System.Integrations.tripletex.form', ['connection' => null])
        </div>
    </section>
    @endif
@endsection
