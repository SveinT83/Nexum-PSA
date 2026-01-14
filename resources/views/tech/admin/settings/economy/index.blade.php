<!-- ------------------------------------------------- -->
<!-- Economy Dashboard -->
<!-- ------------------------------------------------- -->

@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">Economy</h2>
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

    <!-- ------------------------------------------------- -->
    <!-- Show sidebar menu if there are any items -->
    <!-- ------------------------------------------------- -->
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" />
    @endif

@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent Packages (MVP later)</div>
@endsection

