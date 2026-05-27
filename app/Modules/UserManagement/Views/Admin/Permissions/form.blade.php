@extends('layouts.default_tech')

@section('title', 'Permission Management')

@section('pageHeader')

    <!-- Page Name -->
    <h1 class="col-md-auto">Permission Management</h1>

    <!-- Buttons -->
    <div class="col-md-auto">
        <div class="row">
            <div class="col-md-auto">
                <x-buttons.back url="{{ route('tech.admin.user_management.permissions.index') }}"> Back</x-buttons.back>
            </div>
            @if(isset($permission))
                <div class="col-md-auto">
                    <x-buttons.delete url="{{ route('tech.admin.user_management.permissions.destroy', $permission->id) }}" name="{{$permission->name}} permission"></x-buttons.delete>
                </div>
            @endif
        </div>
    </div>

@endsection

@section('content')

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Permissions Edit Card -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    @php
        $formAction = isset($permission->id)
            ? route('tech.admin.user_management.permissions.update', $permission->id)
            : route('tech.admin.user_management.permissions.store');
        $formTitle = $permission->name ?? 'New Permission';
        $saveButton = isset($permission->id)
            ? "Update"
            : "Save permission"
    @endphp

    <x-forms.form-card :title="$formTitle" :buttonText="$saveButton" :action="$formAction">

        @csrf
        <!-- --------------------------------------------- -->
        <!-- Permission Name -->
        <!-- --------------------------------------------- -->
        <div class="row">
            <div class="col-md-6">
                <x-forms.input_text name="name" labelName="Permission Name" value="{{ $permission->name ?? '' }}" />
            </div>
        </div>

    </x-forms.form-card>

@endsection

@section('sidebar')
    <x-nav.admin-menu group="users" />
@endsection

@section('rightbar')
@endsection

