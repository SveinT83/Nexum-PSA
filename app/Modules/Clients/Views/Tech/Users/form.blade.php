@extends('layouts.default_tech')

@php
    $isEdit = isset($user);
    $formTitle = $isEdit ? 'Edit User' : 'Create User';
@endphp

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1>{{ $isEdit ? ($user->name ?? 'Unknown') : 'New User' }}</h1>
        <div>
            @if(isset($client))
                <a href="{{ route('tech.clients.show', $client->id) }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>
            @else
                <a href="{{ route('tech.clients.index') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>
            @endif
        </div>
    </div>
@endsection

@section('content')

    @php
        $action = isset($user)
            ? route('tech.clients.user.update', $user->id)
            : route('tech.clients.user.store', $client->id);
    @endphp

    <div class="card">
        <div class="card-header">
            <h2 class="h5 mb-0">{{ $formTitle }}</h2>
        </div>
        <div class="card-body">
            <x-forms.form-default action="{{ $action }}" method="POST">
                @if($isEdit)
                    @method('PUT')
                @endif

                <!-- ------------------------------------------------- -->
                <!-- Name -->
                <!-- ------------------------------------------------- -->
                <div class="row mt-3">
                    <x-forms.input_text labelName="Name*" layout="vertical" name="name" inputVar="required" value="{{ old('name', $user->name ?? '') }}" />
                </div>

                <!-- ------------------------------------------------- -->
                <!-- E-mail -->
                <!-- ------------------------------------------------- -->
                <div class="row mt-3">
                    <x-forms.input_text name="email" labelName="E-mail*" layout="vertical" type="mail" inputVar="required" value="{{ old('email', $user->email ?? '') }}" />
                </div>

                <!-- ------------------------------------------------- -->
                <!-- Phone -->
                <!-- ------------------------------------------------- -->
                <div class="row mt-3">
                    <x-forms.input_text name="phone" labelName="Phone" layout="vertical" type="tel" value="{{ old('phone', $user->phone ?? '') }}" />
                </div>

                <!-- ------------------------------------------------- -->
                <!-- Role -->
                <!-- ------------------------------------------------- -->
                <div class="row mt-3">
                    <x-forms.input_text name="role" labelName="Role" layout="vertical" value="{{ old('role', $user->role ?? '') }}"></x-forms.input_text>
                </div>

                <!-- ------------------------------------------------- -->
                <!-- Site -->
                <!-- ------------------------------------------------- -->
                <div class="row mt-3">
                    <x-forms.select name="client_site_id" layout="vertical" labelName="Site*" inputVar="required">
                        @if(isset($activeSite))
                            <option value="{{ $activeSite->id }}">{{ $activeSite->name }}</option>
                        @else
                            <option value="">Choose a site</option>
                        @endif

                        @if(isset($sites))
                            @foreach($sites as $site)
                                @if(!isset($activeSite) || $site->id != $activeSite->id)
                                    <option value="{{ $site->id }}">{{ $site->name }}</option>
                                @endif
                            @endforeach
                        @endif
                    </x-forms.select>
                </div>

                <!-- ------------------------------------------------- -->
                <!-- Address -->
                <!-- ------------------------------------------------- -->
                <div class="row mt-3">
                    <b class="col-md-3">Address</b>
                    <div class="col">
                        <x-forms.input_text name="address" value="{{ old('address', $user->address ?? '') }}"></x-forms.input_text>
                        <x-forms.input_text name="co_address" input_class="mt-2" placeholder="CO Address" value="{{ old('co_address', $user->co_address ?? '') }}"></x-forms.input_text>
                    </div>
                </div>

                <!-- ------------------------------------------------- -->
                <!-- Zip Code -->
                <!-- ------------------------------------------------- -->
                <div class="row mt-3">
                    <x-forms.input_text name="zip" layout="vertical" input_class="col-md-2" type="number" labelName="Zip" value="{{ old('zip', $user->zip ?? '') }}"></x-forms.input_text>
                </div>

                <!-- ------------------------------------------------- -->
                <!-- City -->
                <!-- ------------------------------------------------- -->
                <div class="row mt-3">
                    <x-forms.input_text name="city" layout="vertical" labelName="City" value="{{ old('city', $user->city ?? '') }}"></x-forms.input_text>
                </div>

                <!-- ------------------------------------------------- -->
                <!-- County -->
                <!-- ------------------------------------------------- -->
                <div class="row mt-3">
                    <x-forms.input_text name="county" layout="vertical" labelName="County" value="{{ old('county', $user->county ?? '') }}"></x-forms.input_text>
                </div>

                <!-- ------------------------------------------------- -->
                <!-- Country -->
                <!-- ------------------------------------------------- -->
                <div class="row mt-3">
                    <x-forms.input_text name="country" labelName="Country" layout="vertical" value="{{ old('country', $user->country ?? '') }}"></x-forms.input_text>
                </div>

                <!-- ------------------------------------------------- -->
                <!-- Language -->
                <!-- ------------------------------------------------- -->
                <div class="row mt-3">
                    <x-forms.input_text name="language" labelName="Language" layout="vertical" value="{{ old('language', $user->language ?? '') }}"></x-forms.input_text>
                </div>
            </x-forms.form-default>
        </div>
    </div>

@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" title="Client workspace" />
    @endif
@endsection
