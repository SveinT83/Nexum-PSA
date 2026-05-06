@extends('layouts.default_tech')

@section('title', 'Permissions Management')

@section('pageHeader')
    <h1>Permissions Management</h1>
@endsection

@section('content')

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Permissions Card -->
    <!-- -------------------------------------------------------------------------------------------------- -->

    <div class="card">

        <!-- --------------------------------------------- -->
        <!-- Card header -->
        <!-- Card title and add button -->
        <!-- --------------------------------------------- -->
        <div class="card-header">
            <div class="row justify-content-between">

                <!-- Card Title -->
                <h4 class="col-md-8">Permissions</h4>

                <!-- Add button -->
                <div class="col-md-4 text-end">
                    <x-buttons.addlink url="{{ route('tech.admin.user_management.permissions.create') }}"> Add</x-buttons.addlink>
                </div>
            </div>
        </div>

        <!-- --------------------------------------------- -->
        <!-- For each permission an row whit permission name and -->
        <!-- a edit button -->
        <!-- --------------------------------------------- -->
        <div class="card-body">
            @if($permissions->isEmpty())
                <p class="text-muted">No permissions found.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($permissions as $permission)
                                <tr>
                                    <td><b>{{ $permission->name }}</b></td>
                                    <td class="text-end">
                                        <x-buttons.editlink url="{{ route('tech.admin.user_management.permissions.edit', $permission->id) }}"> Edit</x-buttons.editlink>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

@endsection

@section('sidebar')
    <!-- Sidebar Menu Item -->
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" />
    @endif
@endsection

@section('rightbar')
    <h3>Notifications</h3>
    <ul>
        <li>No new notifications.</li>
    </ul>
@endsection
