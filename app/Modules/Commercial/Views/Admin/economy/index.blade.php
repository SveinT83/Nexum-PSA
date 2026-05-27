<!-- ------------------------------------------------- -->
<!-- Economy Dashboard -->
<!-- ------------------------------------------------- -->

@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Economy</h1>
    </div>
@endsection

@section('content')

<x-forms.form-default action="{{route('tech.admin.settings.economy.update')}}" buttonText="Update">

    <div class="row">

        <!-- ------------------------------------------------- -->
        <!-- Vat - Default 25% -->
        <!-- ------------------------------------------------- -->
        <div class="col-md-2">
            <x-forms.input_text name="vat" labelName="VAT (Tax)" type="number" value="{{$vat->value ?? '25'}}"></x-forms.input_text>
            <i>Value in percent!</i>
        </div>

    </div>

</x-forms.form-default>

@endsection

@section('sidebar')
    <x-nav.admin-menu group="economy" />
@endsection

@section('rightbar')
@endsection
