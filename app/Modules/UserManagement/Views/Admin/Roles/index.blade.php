@extends('layouts.default_tech')

@section('title', 'Users Management')

@section('pageHeader')
    <h1>Roles Management</h1>
@endsection

@section('content')

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Roles table -->
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
        <!-- Role rows with permission and user counts -->
        <!-- --------------------------------------------- -->
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Role</th>
                        <th scope="col" class="text-end">Permissions</th>
                        <th scope="col" class="text-end">Users</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($roles as $role)
                        <tr class="cursor-pointer"
                            style="cursor: pointer;"
                            data-href="{{ route('tech.admin.user_management.roles.edit', $role->id) }}"
                            title="Open role"
                            onclick="window.location.href = this.dataset.href">
                            <td>
                                <span class="fw-semibold">{{ $role->name }}</span>
                            </td>
                            <td class="text-end">
                                <span class="badge text-bg-light">{{ $role->permissions_count }}</span>
                            </td>
                            <td class="text-end">
                                <span class="badge text-bg-light">{{ $role->users_count }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted py-4">No roles found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@endsection

@section('sidebar')
    <x-nav.admin-menu group="users" />
@endsection

@section('rightbar')
@endsection
