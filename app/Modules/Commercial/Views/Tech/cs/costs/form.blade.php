@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>{{ isset($cost) ? 'Edit cost' : 'Create cost' }}</h1>
        <div>
            <x-buttons.back url="{{ route('tech.costs.index') }}" class="mb-0">Back</x-buttons.back>
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

    <div class="card">
        <div class="card-header">
            <h2 class="h5 mb-0">{{ $isEdit ? 'Edit Cost' : 'Create Cost' }}</h2>
        </div>
        <div class="card-body">
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
                    <div class="col-md-2 mb-3">
                        <x-forms.input_text name="cost" labelName="Cost:" type="number" value="{{$cost->cost ?? ''}}" inputVar="required"></x-forms.input_text>
                    </div>

                    <!-- Cost PR. User, Client? -->
                    <div class="col-md-2 mb-3">
                        <x-forms.select name="unitId" labelName="Cost unit">
                            @if(isset($cost) && $cost->unit)
                                <option value="{{ $cost->unit->id }}">{{ $cost->unit->name }}</option>
                            @else
                                <option value="" disabled selected>Select unit</option>
                            @endif

                            @foreach($units as $unit)
                                <option value="{{$unit->id}}">{{$unit->name}}</option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <!-- Cost Recurrence -->
                    <div class="col-md-2 mb-3">
                        <x-forms.select name="recurrence" labelName="Recurrence">
                            @if(isset($cost))
                                <option value="{{$cost->recurrence}}">{{ ucfirst($cost->recurrence) }}</option>
                            @else
                                <option value="" disabled selected>Select recurrence</option>
                            @endif
                            <option value="none">None</option>
                            <option value="month">Month</option>
                            <option value="year">Year</option>
                            <option value="quarter">Quarter</option>
                        </x-forms.select>
                    </div>

                    <!-- Vendor -->
                    <div class="col-md-2 mb-3">
                        <x-forms.select name="vendor_id" labelName="Vendor">
                        @if(isset($cost) && $cost->vendor)
                            <option value="{{ $cost->vendor->id }}">{{ $cost->vendor->name }}</option>
                        @else
                            <option value="" disabled selected>Select vendor</option>
                        @endif

                        @foreach($vendors ?? [] as $vendor)
                            <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                        @endforeach
                    </x-forms.select>

                    </div>

                    <div class="col-12">
                        <x-forms.textarea name="note" labelName="Note">{{$cost->note ?? ''}}</x-forms.textarea>
                    </div>

                </div>
            </x-forms.form-default>
        </div>
    </div>

@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
@endsection
