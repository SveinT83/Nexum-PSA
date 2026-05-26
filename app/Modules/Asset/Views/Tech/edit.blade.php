@extends('layouts.default_tech')

@section('title', 'Edit Asset: ' . $asset->name)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Edit: {{ $asset->name }}</h1>
        <div class="btn-group">
            <x-buttons.back :url="route('tech.assets.show', $asset->id)" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.work-menu />
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
