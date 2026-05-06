@extends('layouts.default_tech')

@section('title', 'Edit Asset: ' . $asset->name)

@section('pageHeader')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('tech.assets.index') }}">Assets</a></li>
            <li class="breadcrumb-item"><a href="{{ route('tech.assets.show', $asset->id) }}">{{ $asset->name }}</a></li>
            <li class="breadcrumb-item active" aria-current="page">Edit</li>
        </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-center">
        <h1>Edit: {{ $asset->name }}</h1>
        <div class="btn-group">
            <x-buttons.back :url="route('tech.assets.show', $asset->id)">Back to Asset</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @livewire('tech.assets.asset-form', ['asset' => $asset])
                </div>
            </div>
        </div>
    </div>
@endsection
