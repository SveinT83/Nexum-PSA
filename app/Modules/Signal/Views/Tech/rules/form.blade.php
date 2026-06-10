@extends('layouts.default_tech')

@section('title', 'New Signal Rule')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="h4 mb-0">New Signal Rule</h1>
        <a href="{{ route('tech.admin.system.signals.rules.index') }}" class="btn btn-sm btn-outline-secondary">Rules</a>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Signal rule form -->
    <!-- ------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.admin.system.signals.rules.store') }}" class="d-grid gap-3">
        @csrf
        @include('signal::Tech.rules.partials.fields', ['rule' => $rule])
    </form>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="system" />
@endsection
