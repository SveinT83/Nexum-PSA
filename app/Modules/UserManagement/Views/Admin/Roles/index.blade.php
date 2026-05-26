@extends('layouts.default_tech')

@section('title', 'Users Management')

@section('pageHeader')
    <h1>Roles Management</h1>
@endsection

@section('content')

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Roles Card -->
    <!-- -------------------------------------------------------------------------------------------------- -->

    <div class="card">

        <!-- --------------------------------------------- -->
        <!-- Card header -->
        <!-- Card title and add button -->
        <!-- --------------------------------------------- -->
        <div class="card-header">
            <div class="row justify-content-between">

                <!-- Card Title -->
                <h4 class="col-md-8">Roles</h4>

                <!-- Add button -->
                <div class="col-md-4 text-end">
                    <x-buttons.addlink url="{{ route('tech.admin.user_management.roles.create') }}"> Add</x-buttons.addlink>
                </div>
            </div>
        </div>

        <!-- --------------------------------------------- -->
        <!-- For each role an row whit role name and -->
        <!-- permissions and a edit button -->
        <!-- --------------------------------------------- -->
        <div class="card-body">
            @foreach($roles as $role)
                <div class="row mt-2">

                    <!-- Role Name -->
                    <b class="col-md-10">{{ $role->name }}</b>

                    <!-- Edit button -->
                    <div class="col-md-2 text-end">
                        @if($role->name != 'Superuser')
                            <x-buttons.editlink url="{{ route('tech.admin.user_management.roles.edit', $role->id) }}"> Edit</x-buttons.editlink>
                        @endif
                    </div>
                </div>

                <div class="row mb-2 border-bottom">
                    <!-- --------------------------------------------- -->
                    <!-- If no permissions -->
                    <!-- --------------------------------------------- -->
                    @if($role->permissions->isEmpty())
                        <div class="col-12">
                            <p class="text-muted">This role has no permissions</p>
                        </div>
                    @else

                        <!-- --------------------------------------------- -->
                        <!-- For each permission an col whit permission name -->
                        <!-- --------------------------------------------- -->
                        @foreach($role->permissions as $permission)
                            <div class="col-auto">
                                <p>{{ $permission->name }}</p>
                            </div>
                        @endforeach
                    @endif

                </div>
            @endforeach
        </div>
    </div>

@endsection

@section('sidebar')
    <x-nav.admin-menu group="users" />
@endsection

@section('rightbar')
@endsection
