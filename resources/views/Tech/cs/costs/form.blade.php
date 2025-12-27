@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">Create cost</h2>
        <div>
            <a href="{{ route('tech.costs.index') }}" class="btn btn-sm btn-primary">Back</a>
        </div>
    </div>
@endsection

@section('content')

    <!-- ------------------------------------------------- -->
    <!-- Alert message -->
    <!-- ------------------------------------------------- -->
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <!-- ------------------------------------------------- -->
    <!-- Form -->
    <!-- ------------------------------------------------- -->
    <x-forms.form-default action="tech.costs.store" button-text="Save">

        <div class="row">

            <!-- Cost Name -->
            <div class="col-md-4 mb-3">
                <x-forms.input_text name="name" labelName="Name:"></x-forms.input_text>
            </div>

            <!-- Cost EX vat -->
            <div class="col-md-2 mb-3">
                <x-forms.input_text name="cost" labelName="Cost ex. vat:" type="number"></x-forms.input_text>
            </div>

            <!-- Cost PR. User, Client? -->
            <div class="col-md-3 mb-3">
                <x-forms.select name="unit" labelName="Cost unit">
                    <option value="client">Client</option>
                    <option value="user">User</option>
                    <option value="site">Site</option>
                    <option value="asset">Asset</option>
                </x-forms.select>
            </div>

            <!-- Cost Recurrence -->
            <div class="col-md-3 mb-3">
                <x-forms.select name="recurrence" labelName="Recurrence">
                    <option value="month">Month</option>
                    <option value="user">User</option>
                    <option value="site">Site</option>
                    <option value="asset">Asset</option>
                </x-forms.select>
            </div>
        </div>

    </x-forms.form-default>

@endsection

@section('sidebar')
    <div class="p-3 small text-muted">Service filters (later)</div>
@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent services (MVP later)</div>
@endsection
