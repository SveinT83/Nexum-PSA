@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">Create cost</h2>
        <div>
            <a href="{{ route('tech.costs.index') }}" class="btn btn-sm btn-secondary">Back</a>
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
    @php $isEdit = isset($cost); @endphp

    <x-forms.form-default
        action="{{ $isEdit ? route('tech.costs.update', $cost) : route('tech.costs.store') }}"
        method="post"
        button-text="{{ $isEdit ? 'Update' : 'Save' }}">


        <div class="row">

            <!-- Cost Name -->
            <div class="col-md-4 mb-3">
                <x-forms.input_text name="name" labelName="Name:" value="{{$cost->name ?? ''}}" inputVar="required"></x-forms.input_text>
            </div>

            <!-- Cost EX vat -->
            <div class="col-md-1 mb-3">
                <x-forms.input_text name="cost" labelName="Cost:" type="number" value="{{$cost->cost ?? ''}}" inputVar="required"></x-forms.input_text>
            </div>

            <!-- Cost PR. User, Client? -->
            <div class="col-md-2 mb-3">
                <x-forms.select name="unit" labelName="Cost unit">
                    <option value="{{$cost->client ?? 'client'}}">{{$cost->client ?? 'Client'}}</option>
                    <option value="user">User</option>
                    <option value="site">Site</option>
                    <option value="asset">Asset</option>
                    <option value="license">License</option>
                    <option value="other">Other</option>
                </x-forms.select>
            </div>

            <!-- Cost Recurrence -->
            <div class="col-md-2 mb-3">
                <x-forms.select name="recurrence" labelName="Recurrence">
                    <option value="{{$cost->recurrence ?? 'none'}}">{{$cost->recurrence ?? 'none'}}</option>
                    <option value="month">Month</option>
                    <option value="year">Year</option>
                    <option value="quarter">Quarter</option>
                </x-forms.select>
            </div>

            <!-- Vendor -->
            <div class="col-md-2 mb-3">
                <x-forms.select name="vendor_id" labelName="Vendor">
                    @forelse($vendors ?? [] as $vendor)
                        <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                    @empty
                        <option value="" disabled selected>No vendors</option>
                    @endforelse
                </x-forms.select>

            </div>

            <div class="row">
                <div class="col-12">
                    <x-forms.textarea name="note" labelName="Note">{{$cost->note ?? 'none'}}</x-forms.textarea>
                </div>
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
