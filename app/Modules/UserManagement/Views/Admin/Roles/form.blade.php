@extends('layouts.default_tech')

@section('title', 'Role Management')

@section('pageHeader')

    <!-- Page Name -->
    <h1 class="col-md-auto">Role Management</h1>

    <!-- Buttons -->
    <div class="col-md-auto">
        <div class="row">
            <div class="col-md-auto">
                <x-buttons.back url="{{ route('tech.admin.user_management.roles.index') }}"> Back</x-buttons.back>
            </div>
            @if(isset($role))
                <div class="col-md-auto">
                    <x-buttons.delete url="{{ route('tech.admin.user_management.roles.destroy', $role->id) }}" name="{{$role->name}} permission"></x-buttons.delete>
                </div>
            @endif
        </div>
    </div>

@endsection

@section('content')



    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Roles Edit Card -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    @php
        $formAction = isset($role->id)
            ? route('tech.admin.user_management.roles.update', $role->id)
            : route('tech.admin.user_management.roles.store');
        $formTitle = $role->name ?? 'New Role';
        $saveButton = isset($role->id)
            ? "Update"
            : "Save role"
    @endphp

    <x-forms.form-card :title="$formTitle" :buttonText="$saveButton" :action="$formAction">

        @csrf
        <!-- --------------------------------------------- -->
        <!-- Role Name -->
        <!-- --------------------------------------------- -->
        <div class="row">
            <div class="col-md-6">
                <x-forms.input_text name="name" labelName="Role Name" value="{{ $role->name ?? '' }}" />
            </div>
        </div>


        <!-- --------------------------------------------- -->
        <!-- Permissions (Livewire) -->
        <!-- Only show if in Edit route -->
        <!-- --------------------------------------------- -->
        @if(isset($role) && $role->exists)
            <div class="mt-4">
                @livewire('tech.admin.user_management.roles.role-permissions', ['roleId' => $role->id])
            </div>
        @else
            <p>Create the role first and then add permissions</p>
        @endif
    </x-forms.form-card>


@endsection

@section('sidebar')

    <!-- Sidebar Menu Item -->
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" />
    @endif
@endsection

@section('rightbar')
@endsection

