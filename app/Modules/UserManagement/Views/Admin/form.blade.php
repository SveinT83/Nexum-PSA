@extends('layouts.default_tech')

@section('title', 'Users Management')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between">
        <h1>Users Management</h1>
        <x-buttons.back url="{{ route('tech.admin.user_management.index') }}">Back</x-buttons.back>
    </div>
@endsection

@section('content')

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- PHP variables -->
    <!-- If new user or edit user -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    @php
        $formTitle = 'Create User';
        $formAction = route('tech.admin.user_management.store');
        $formMethod = 'POST';
        $saveButton = 'Create User';
    @endphp

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Create User form Card -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <x-forms.form-card :title="$formTitle" :buttonText="$saveButton" :action="$formAction">
        @csrf

        <!-- --------------------------------------------- -->
        <!-- ROW of user name and e-mail -->
        <!-- --------------------------------------------- -->
        <div class="row">
            <div class="col-md-6">
                <x-forms.input_text name="name" labelName="Name" :value="old('name')" />
            </div>

            <div class="col-md-6">
                <x-forms.input_text name="email" labelName="Email" type="email" placeholder="name@example.com" :value="old('email')" />
            </div>
        </div>

        <!-- --------------------------------------------- -->
        <!-- ROW of user role -->
        <!-- --------------------------------------------- -->
        <div class="row mt-3">
            <div class="col-md-6">
                <x-forms.select name="role" labelName="Role" :options="$roles" :selected="old('role')" >

                    <!-- --------------------------------------------- -->
                    <!-- Role options -->
                    <!-- --------------------------------------------- -->
                    <option value=""> Choose a role</option>

                    @foreach($roles as $role)
                        <option value="{{ $role->id }}" {{ old('role') == $role->id ? 'selected' : '' }}>{{ $role->name }}</option>
                    @endforeach

                </x-forms.select>
            </div>

            <div class="col-md-6">
                <x-forms.select name="status" labelName="Status">
                    <option value="{{ \App\Models\Core\User::STATUS_PENDING }}" {{ old('status') === \App\Models\Core\User::STATUS_PENDING ? 'selected' : '' }}>Pending Invite</option>
                    <option value="{{ \App\Models\Core\User::STATUS_ACTIVE }}" {{ old('status') === \App\Models\Core\User::STATUS_ACTIVE ? 'selected' : '' }}>Active</option>
                    <option value="{{ \App\Models\Core\User::STATUS_DISABLED }}" {{ old('status') === \App\Models\Core\User::STATUS_DISABLED ? 'selected' : '' }}>Disabled</option>
                </x-forms.select>
            </div>
        </div>

    </x-forms.form-card>

@endsection

@section('sidebar')
    <x-nav.admin-menu group="users" />
@endsection

@section('rightbar')
@endsection
